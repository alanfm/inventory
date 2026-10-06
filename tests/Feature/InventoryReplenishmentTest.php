<?php

namespace Acme\Inventory\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryReplenishmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_recommendation_uses_complete_days_and_offsets_only_reversed_issues(): void
    {
        CarbonImmutable::setTestNow('2026-09-29 11:00:00');
        [$user, $item, $variant, $location] = $this->fixture(['inventory.items.view']);
        DB::table('inventory_items')->where('id', $item)->update([
            'history_coverage_from' => '2026-04-02', 'recommendation_window_days' => 180,
            'purchase_lead_time_days' => 30, 'safety_stock' => 5,
        ]);
        $this->ledger($user->id, $location, $variant, 'ISSUE', '2026-07-01', -100);
        $this->ledger($user->id, $location, $variant, 'ISSUE', '2026-07-02', -10);
        $this->ledger($user->id, $location, $variant, 'REVERSAL', '2026-09-28', 20, 'ISSUE');
        $this->ledger($user->id, $location, $variant, 'REVERSAL', '2026-04-01', 30, 'ISSUE');
        $this->ledger($user->id, $location, $variant, 'ISSUE', '2026-09-29', -500);

        $this->actingAs($user)->getJson("/api/v1/inventory/items/{$item}/replenishment")
            ->assertOk()->assertJsonPath('data.status', 'CALCULABLE')
            ->assertJsonPath('data.from', '2026-04-02')->assertJsonPath('data.to', '2026-09-28')
            ->assertJsonPath('data.consumption', 90)->assertJsonPath('data.suggestedMinimumStock', 20);
    }

    public function test_zero_consumption_keeps_safety_stock_and_negative_variant_is_inconsistent(): void
    {
        CarbonImmutable::setTestNow('2026-09-29 11:00:00');
        [$user, $item, $variant] = $this->fixture(['inventory.items.view']);
        DB::table('inventory_items')->where('id', $item)->update([
            'history_coverage_from' => '2026-04-02', 'purchase_lead_time_days' => 30,
            'safety_stock' => 5, 'minimum_stock' => 1,
        ]);
        $this->actingAs($user)->getJson("/api/v1/inventory/items/{$item}/replenishment")
            ->assertOk()->assertJsonPath('data.status', 'CALCULABLE')
            ->assertJsonPath('data.consumption', 0)->assertJsonPath('data.suggestedMinimumStock', 5)
            ->assertJsonPath('data.situation', 'REPLENISHMENT');

        DB::table('inventory_items')->where('id', $item)->update(['safety_stock' => 0]);
        $this->getJson("/api/v1/inventory/items/{$item}/replenishment")
            ->assertOk()->assertJsonPath('data.suggestedMinimumStock', 0);

        DB::table('inventory_balances')->where('variant_id', $variant)->update(['quantity' => -1]);
        $this->getJson("/api/v1/inventory/items/{$item}/replenishment")
            ->assertOk()->assertJsonPath('data.situation', 'INCONSISTENT');
    }

    public function test_recommendation_explains_missing_parameters_and_insufficient_coverage(): void
    {
        CarbonImmutable::setTestNow('2026-09-29 11:00:00');
        [$user, $item] = $this->fixture(['inventory.items.view']);
        $this->actingAs($user)->getJson("/api/v1/inventory/items/{$item}/replenishment")
            ->assertOk()->assertJsonPath('data.status', 'NOT_CALCULABLE')
            ->assertJsonPath('data.reason', 'REPLENISHMENT_PARAMETERS_MISSING');

        DB::table('inventory_items')->where('id', $item)->update([
            'history_coverage_from' => '2026-04-03', 'purchase_lead_time_days' => 0, 'safety_stock' => 5,
        ]);
        $this->getJson("/api/v1/inventory/items/{$item}/replenishment")
            ->assertOk()->assertJsonPath('data.status', 'INSUFFICIENT_HISTORY')
            ->assertJsonPath('data.reason', 'HISTORY_DOES_NOT_COVER_WINDOW');
    }

    public function test_configuration_requires_dedicated_permission_and_audits_optimistic_update(): void
    {
        [$unauthorized, $item] = $this->fixture([]);
        $this->actingAs($unauthorized)->patchJson("/api/v1/inventory/items/{$item}/replenishment", $this->configuration())
            ->assertForbidden();

        [$manager, $managedItem] = $this->fixture(['inventory.items.configureReplenishment']);
        $this->actingAs($manager)->patchJson("/api/v1/inventory/items/{$managedItem}/replenishment", $this->configuration())
            ->assertOk()->assertJsonPath('data.minimumStock', 20)->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('inventory_audit_events', [
            'entity_type' => 'item', 'entity_id' => $managedItem, 'action' => 'replenishment_configured',
            'actor_id' => $manager->id,
        ]);
        $this->patchJson("/api/v1/inventory/items/{$managedItem}/replenishment", $this->configuration())
            ->assertStatus(409);
    }

    /** @return array{User, int, int, int} */
    private function fixture(array $permissions): array
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        $role = Role::query()->create(['slug' => 'replenishment-'.bin2hex(random_bytes(3)), 'name' => 'Reposição']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user = User::query()->create(['name' => 'Operador', 'email' => 'replenishment-'.bin2hex(random_bytes(4)).'@example.test']);
        $user->roles()->attach($role);
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Reposição', 'normalized_name' => 'rep-'.bin2hex(random_bytes(3)), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('inventory_items')->insertGetId(['code' => 'REP-'.strtoupper(bin2hex(random_bytes(2))), 'category_id' => $category, 'name' => 'Item', 'unit' => 'UN', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $variant = DB::table('inventory_product_variants')->insertGetId(['item_id' => $item, 'description' => 'Variante', 'identity_hash' => hash('sha256', (string) $item), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $location = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');
        DB::table('inventory_balances')->insert(['location_id' => $location, 'variant_id' => $variant, 'quantity' => 1, 'version' => 1]);

        return [$user, $item, $variant, $location];
    }

    private function ledger(int $actor, int $location, int $variant, string $type, string $date, int $delta, ?string $reversedType = null): void
    {
        $originalId = null;
        if ($reversedType !== null) {
            $originalId = DB::table('inventory_movements')->insertGetId(['type' => $reversedType, 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => $date, 'version' => 2, 'posted_by' => $actor, 'posted_at' => $date, 'created_by' => $actor, 'created_at' => $date, 'updated_at' => $date]);
        }
        $movement = DB::table('inventory_movements')->insertGetId(['type' => $type, 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => $date, 'reverses_movement_id' => $originalId, 'version' => 2, 'posted_by' => $actor, 'posted_at' => $date, 'created_by' => $actor, 'created_at' => $date, 'updated_at' => $date]);
        $line = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $movement, 'variant_id' => $variant, 'quantity' => abs($delta), 'snapshot' => json_encode(['description' => 'Variante'], JSON_THROW_ON_ERROR), 'created_at' => $date, 'updated_at' => $date]);
        DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $line, 'variant_id' => $variant, 'location_id' => $location, 'effective_on' => $date, 'delta' => $delta, 'posted_at' => $date]);
    }

    private function configuration(): array
    {
        return ['version' => 1, 'minimumStock' => 20, 'purchaseLeadTimeDays' => 30, 'safetyStock' => 5, 'recommendationWindowDays' => 180];
    }
}
