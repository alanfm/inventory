<?php

namespace Acme\Inventory\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryMovementDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_draft_requires_explanation_without_order_and_is_owned_by_creator(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        [$user, $variant] = $this->fixture();
        $location = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');
        $this->actingAs($user)->withHeader('Idempotency-Key', 'incomplete-draft')->postJson('/api/v1/inventory/movements', [
            'type' => 'ISSUE', 'locationId' => $location, 'description' => 'Atendimento de rede',
            'lines' => [['variantId' => $variant, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.observations', null);

        $draft = $this->withHeader('Idempotency-Key', 'create-issue-draft')->postJson('/api/v1/inventory/movements', [
            'type' => 'ISSUE', 'locationId' => $location, 'description' => 'Atendimento de rede', 'observations' => 'Sem OS no chamado',
            'lines' => [['variantId' => $variant, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.lines.0.quantity', 2);
        $movementId = $draft->json('data.id');
        $retry = $this->withHeader('Idempotency-Key', 'create-issue-draft')->postJson('/api/v1/inventory/movements', [
            'type' => 'ISSUE', 'locationId' => $location, 'description' => 'Atendimento de rede', 'observations' => 'Sem OS no chamado',
            'lines' => [['variantId' => $variant, 'quantity' => 2]],
        ])->assertCreated();
        self::assertSame($movementId, $retry->json('data.id'));
        $this->assertDatabaseCount('inventory_movements', 2);

        $otherUser = $this->userWithPermission('inventory.issues.create');
        $this->actingAs($otherUser)->postJson("/api/v1/inventory/movements/{$movementId}/cancel")->assertForbidden();
        $this->actingAs($user)->postJson("/api/v1/inventory/movements/{$movementId}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        self::assertDatabaseCount('inventory_ledger_entries', 0);
    }

    public function test_entry_without_location_or_balance_initializes_default_and_posts_once(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        [$user, $variant] = $this->fixture();
        $entryRole = $this->userWithPermission('inventory.entries.create')->roles()->sole();
        $user->roles()->attach($entryRole);
        DB::table('inventory_locations')->delete();
        $payload = ['type' => 'ENTRY', 'occurredOn' => now()->toDateString(), 'origin' => 'PURCHASE', 'documentNumber' => 'TEST-001', 'lines' => [['variantId' => $variant, 'quantity' => 3]]];

        $created = $this->actingAs($user)->withHeader('Idempotency-Key', 'first-entry')->postJson('/api/v1/inventory/movements', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $locationId = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');
        $created->assertJsonPath('data.location_id', $locationId);
        $this->assertDatabaseCount('inventory_locations', 1);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('inventory_ledger_entries', 0);
        $this->postJson('/api/v1/inventory/movements', $payload)->assertCreated()->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseCount('inventory_movements', 1);

        $this->withHeader('Idempotency-Key', 'post-first-entry')->postJson('/api/v1/inventory/movements/'.$created->json('data.id').'/post', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/inventory/movements/'.$created->json('data.id').'/post', ['version' => 1])->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['variant_id' => $variant, 'location_id' => $locationId, 'quantity' => 3]);
        $this->assertDatabaseCount('inventory_ledger_entries', 1);
    }

    public function test_issue_resolves_default_location_independently_of_variant_balances(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        [$user, $variant] = $this->fixture();
        $locationId = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');
        $otherLocation = DB::table('inventory_locations')->insertGetId(['code' => 'OTHER', 'name' => 'Outro local', 'active' => true]);
        DB::table('inventory_balances')->insert(['location_id' => $otherLocation, 'variant_id' => $variant, 'quantity' => 5, 'version' => 1]);

        $draft = $this->actingAs($user)->withHeader('Idempotency-Key', 'issue-default')->postJson('/api/v1/inventory/movements', ['type' => 'ISSUE', 'occurredOn' => now()->toDateString(), 'description' => 'Teste', 'observations' => 'Sem OS', 'lines' => [['variantId' => $variant, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.location_id', $locationId);
        $this->withHeader('Idempotency-Key', 'post-empty-issue')->postJson('/api/v1/inventory/movements/'.$draft->json('data.id').'/post', ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('inventory_balances', 1);
        $this->assertDatabaseHas('inventory_balances', ['location_id' => $otherLocation, 'quantity' => 5]);
    }

    public function test_inactive_default_location_is_not_reactivated_by_draft_creation(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        [$user, $variant] = $this->fixture();
        DB::table('inventory_locations')->where('code', 'TI')->update(['active' => false]);

        $this->actingAs($user)->withHeader('Idempotency-Key', 'inactive-location')->postJson('/api/v1/inventory/movements', ['type' => 'ISSUE', 'lines' => [['variantId' => $variant, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.locationId.0', 'O local padrão do almoxarifado está inativo. Contate o administrador.');
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_idempotency_keys', 0);
        $this->assertDatabaseHas('inventory_locations', ['code' => 'TI', 'active' => false]);
    }

    public function test_unauthorized_request_cannot_initialize_default_location(): void
    {
        DB::table('inventory_locations')->delete();
        $this->postJson('/api/v1/inventory/movements', ['type' => 'ENTRY'])->assertUnauthorized();
        $user = User::query()->create(['name' => 'Sem acesso', 'email' => 'no-access@example.test']);
        $this->actingAs($user)->postJson('/api/v1/inventory/movements', ['type' => 'ENTRY'])->assertForbidden();
        $this->assertDatabaseCount('inventory_locations', 0);
    }

    /** @return array{User, int} */
    private function fixture(): array
    {
        $user = $this->userWithPermission('inventory.issues.create');
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Draft', 'normalized_name' => 'draft', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('inventory_items')->insertGetId(['code' => 'DRF01', 'category_id' => $category, 'name' => 'Item draft', 'unit' => 'UN', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $variant = DB::table('inventory_product_variants')->insertGetId(['item_id' => $item, 'description' => 'Variante', 'identity_hash' => hash('sha256', 'draft'), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $variant];
    }

    private function userWithPermission(string $permission): User
    {
        $role = Role::query()->create(['slug' => 'draft-'.bin2hex(random_bytes(3)), 'name' => 'Draft']);
        $role->permissions()->sync(Permission::query()->where('name', $permission)->pluck('id'));
        $user = User::query()->create(['name' => 'Operador', 'email' => 'draft-'.bin2hex(random_bytes(4)).'@example.test']);
        $user->roles()->attach($role);

        return $user;
    }
}
