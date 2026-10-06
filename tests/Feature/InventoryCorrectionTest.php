<?php

namespace Acme\Inventory\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_uses_expected_balance_version_and_audits_zero_count_without_ledger_entry(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        [$user, $location, $variant] = $this->fixture(['inventory.adjustments.create']);
        $noPermission = User::query()->create(['name' => 'Almoxarife', 'email' => 'no-adjust-'.bin2hex(random_bytes(4)).'@example.test']);
        $this->actingAs($noPermission)->withHeader('Idempotency-Key', 'unauthorized-count')->postJson('/api/v1/inventory/adjustments', ['locationId' => $location, 'variantId' => $variant, 'countedQuantity' => 9, 'expectedBalanceVersion' => 1, 'reason' => 'Contagem'])->assertForbidden();
        $this->actingAs($user);

        $this->withHeader('Idempotency-Key', 'count-one')->postJson('/api/v1/inventory/adjustments', ['locationId' => $location, 'variantId' => $variant, 'countedQuantity' => 4, 'expectedBalanceVersion' => 1, 'reason' => 'Contagem física'])->assertCreated()->assertJsonPath('data.delta', 4);
        $this->assertDatabaseHas('inventory_balances', ['variant_id' => $variant, 'quantity' => 4, 'version' => 2]);
        $this->withHeader('Idempotency-Key', 'stale-count')->postJson('/api/v1/inventory/adjustments', ['locationId' => $location, 'variantId' => $variant, 'countedQuantity' => 4, 'expectedBalanceVersion' => 1, 'reason' => 'Contagem antiga'])->assertStatus(409);

        $this->withHeader('Idempotency-Key', 'count-zero-delta')->postJson('/api/v1/inventory/adjustments', ['locationId' => $location, 'variantId' => $variant, 'countedQuantity' => 4, 'expectedBalanceVersion' => 2, 'reason' => 'Conferência sem diferença'])->assertCreated()->assertJsonPath('data.delta', 0);
        $this->assertDatabaseCount('inventory_ledger_entries', 1);
        $this->assertDatabaseHas('inventory_audit_events', ['action' => 'inventory_count_checked', 'reason' => 'Conferência sem diferença']);
    }

    public function test_reversal_is_integral_idempotent_and_does_not_allow_second_reversal(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        [$user, $location, $variant] = $this->fixture(['inventory.movements.reverse']);
        $movementId = DB::table('inventory_movements')->insertGetId(['type' => 'ENTRY', 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => now()->subDay()->toDateString(), 'version' => 2, 'posted_by' => $user->id, 'posted_at' => now()->subDay(), 'created_by' => $user->id, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $lineId = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $movementId, 'variant_id' => $variant, 'quantity' => 4, 'snapshot' => json_encode(['description' => 'Variante'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $lineId, 'variant_id' => $variant, 'location_id' => $location, 'effective_on' => now()->subDay()->toDateString(), 'delta' => 4, 'posted_at' => now()->subDay()]);
        DB::table('inventory_balances')->where(['location_id' => $location, 'variant_id' => $variant])->update(['quantity' => 4, 'version' => 2]);

        $this->actingAs($user);
        $first = $this->withHeader('Idempotency-Key', 'reverse-entry')->postJson("/api/v1/inventory/movements/{$movementId}/reverse", ['reason' => 'Entrada duplicada'])->assertCreated()->assertJsonPath('data.status', 'REVERSED');
        $second = $this->withHeader('Idempotency-Key', 'reverse-entry')->postJson("/api/v1/inventory/movements/{$movementId}/reverse", ['reason' => 'Entrada duplicada'])->assertCreated();
        self::assertSame($first->json('data.reversalId'), $second->json('data.reversalId'));
        $this->assertDatabaseHas('inventory_balances', ['variant_id' => $variant, 'quantity' => 0, 'version' => 3]);
        $this->assertDatabaseHas('inventory_ledger_entries', ['variant_id' => $variant, 'delta' => -4]);
        $this->withHeader('Idempotency-Key', 'reverse-again')->postJson("/api/v1/inventory/movements/{$movementId}/reverse", ['reason' => 'Segunda tentativa'])->assertStatus(409);
    }

    public function test_reversal_is_rejected_when_it_would_make_a_variant_negative(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        [$user, $location, $variant] = $this->fixture(['inventory.movements.reverse']);
        $entry = DB::table('inventory_movements')->insertGetId(['type' => 'ENTRY', 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => now()->subDays(2)->toDateString(), 'version' => 2, 'posted_by' => $user->id, 'posted_at' => now()->subDays(2), 'created_by' => $user->id, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $entryLine = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $entry, 'variant_id' => $variant, 'quantity' => 4, 'snapshot' => json_encode(['description' => 'Variante'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $entryLine, 'variant_id' => $variant, 'location_id' => $location, 'effective_on' => now()->subDays(2)->toDateString(), 'delta' => 4, 'posted_at' => now()->subDays(2)]);
        $issue = DB::table('inventory_movements')->insertGetId(['type' => 'ISSUE', 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => now()->subDay()->toDateString(), 'version' => 2, 'posted_by' => $user->id, 'posted_at' => now()->subDay(), 'created_by' => $user->id, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $issueLine = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $issue, 'variant_id' => $variant, 'quantity' => 3, 'snapshot' => json_encode(['description' => 'Variante'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $issueLine, 'variant_id' => $variant, 'location_id' => $location, 'effective_on' => now()->subDay()->toDateString(), 'delta' => -3, 'posted_at' => now()->subDay()]);
        DB::table('inventory_balances')->where(['location_id' => $location, 'variant_id' => $variant])->update(['quantity' => 1, 'version' => 3]);

        $this->actingAs($user)->withHeader('Idempotency-Key', 'negative-reversal')->postJson("/api/v1/inventory/movements/{$entry}/reverse", ['reason' => 'Entrada incorreta'])->assertStatus(409);
        $this->assertDatabaseHas('inventory_movements', ['id' => $entry, 'status' => 'POSTED']);
        $this->assertDatabaseCount('inventory_ledger_entries', 2);
    }

    /** @param list<string> $permissions @return array{User, int, int} */
    private function fixture(array $permissions): array
    {
        $role = Role::query()->create(['slug' => 'correction-'.bin2hex(random_bytes(3)), 'name' => 'Corretor']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user = User::query()->create(['name' => 'Gestor', 'email' => 'correction-'.bin2hex(random_bytes(4)).'@example.test']);
        $user->roles()->attach($role);
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Correção', 'normalized_name' => 'correcao-'.bin2hex(random_bytes(3)), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('inventory_items')->insertGetId(['code' => 'COR-'.strtoupper(bin2hex(random_bytes(2))), 'category_id' => $category, 'name' => 'Item correção', 'unit' => 'UN', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $variant = DB::table('inventory_product_variants')->insertGetId(['item_id' => $item, 'description' => 'Variante', 'identity_hash' => hash('sha256', (string) $item), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $location = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');
        DB::table('inventory_balances')->insert(['location_id' => $location, 'variant_id' => $variant, 'quantity' => 0, 'version' => 1]);

        return [$user, $location, $variant];
    }
}
