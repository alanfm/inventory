<?php

namespace Acme\Inventory\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_normalizes_code_and_category_and_tracks_multiple_variants(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        $this->actingAs($this->userWithPermissions(['inventory.categories.viewAny', 'inventory.categories.create', 'inventory.categories.update', 'inventory.items.viewAny', 'inventory.items.view', 'inventory.items.create', 'inventory.items.update', 'inventory.variants.create', 'inventory.variants.update']));

        $category = $this->postJson('/api/v1/inventory/categories', ['name' => '  Memória  ', 'observations' => ' Categoria de componentes. '])->assertCreated()->assertJsonPath('data.name', 'Memória')->assertJsonPath('data.observations', 'Categoria de componentes.');
        $this->postJson('/api/v1/inventory/categories', ['name' => 'memória'])->assertUnprocessable();
        $item = $this->postJson('/api/v1/inventory/items', ['code' => 'mem01', 'categoryId' => $category->json('data.id'), 'name' => 'Memória RAM', 'unit' => 'UN'])->assertCreated()->assertJsonPath('data.code', 'MEM01');
        $itemId = $item->json('data.id');

        $this->postJson("/api/v1/inventory/items/{$itemId}/variants", ['brand' => 'Kingston', 'model' => 'KVR', 'description' => '8 GB'])->assertCreated();
        $this->postJson("/api/v1/inventory/items/{$itemId}/variants", ['brand' => ' kingston ', 'model' => 'kvr', 'description' => '8 gb'])->assertUnprocessable();
        $this->postJson("/api/v1/inventory/items/{$itemId}/variants", ['brand' => 'Crucial', 'model' => 'CT', 'description' => '8 GB'])->assertCreated();
        $detail = $this->getJson("/api/v1/inventory/items/{$itemId}")->assertOk()->assertJsonCount(2, 'data.variants');
        self::assertSame([0, 0], array_map(static fn (array $variant): int => $variant['balances'][0]['quantity'], $detail->json('data.variants')));
    }

    public function test_catalog_rejects_stale_versions_and_requires_operation_permissions(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->getJson('/api/v1/inventory/items')->assertUnauthorized();
        $this->actingAs($this->userWithPermissions([]))->postJson('/api/v1/inventory/categories', ['name' => 'Rede'])->assertForbidden();

        $categoryId = DB::table('inventory_categories')->insertGetId(['name' => 'Rede', 'normalized_name' => 'rede', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->userWithPermissions(['inventory.categories.update']))
            ->patchJson("/api/v1/inventory/categories/{$categoryId}", ['name' => 'Redes', 'observations' => 'Acessórios de rede.', 'active' => true, 'version' => 1])
            ->assertOk()->assertJsonPath('data.observations', 'Acessórios de rede.');

        $this->patchJson("/api/v1/inventory/categories/{$categoryId}", ['name' => 'Redes', 'active' => true, 'version' => 99])
            ->assertStatus(409);
    }

    public function test_inactive_category_cannot_be_used_for_new_items_and_catalog_filters_work(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $user = $this->userWithPermissions(['inventory.categories.viewAny', 'inventory.categories.create', 'inventory.categories.update', 'inventory.items.viewAny', 'inventory.items.create']);
        $this->actingAs($user);
        $category = $this->postJson('/api/v1/inventory/categories', ['name' => 'Cabos'])->assertCreated();
        $this->patchJson('/api/v1/inventory/categories/'.$category->json('data.id'), ['name' => 'Cabos', 'active' => false, 'version' => 1])->assertOk();
        $this->postJson('/api/v1/inventory/items', ['code' => 'CAB01', 'categoryId' => $category->json('data.id'), 'name' => 'Cabo USB', 'unit' => 'UN'])->assertUnprocessable();

        $active = $this->postJson('/api/v1/inventory/categories', ['name' => 'Periféricos'])->assertCreated();
        $this->postJson('/api/v1/inventory/items', ['code' => 'MOUS1', 'categoryId' => $active->json('data.id'), 'name' => 'Mouse', 'unit' => 'UN'])->assertCreated();
        $this->getJson('/api/v1/inventory/items?search=MOUS&categoryId='.$active->json('data.id'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MOUS1');
        $this->getJson('/api/v1/inventory/items?search=not-found')->assertOk()->assertJsonCount(0, 'data');
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->create(['slug' => 'inventory-role-'.bin2hex(random_bytes(3)), 'name' => 'Estoque']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user = User::query()->create(['name' => 'Operador', 'email' => 'inventory-'.bin2hex(random_bytes(3)).'@example.test']);
        $user->roles()->attach($role);

        return $user;
    }
}
