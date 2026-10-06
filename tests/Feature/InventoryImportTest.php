<?php

namespace Acme\Inventory\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class InventoryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_complete_tuples_are_committed_and_incomplete_rows_stay_manual(): void
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        Storage::fake('inventory-imports');
        $user = $this->userWithImportPermission();
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Import', 'normalized_name' => 'import', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('inventory_items')->insertGetId(['code' => 'IMP01', 'category_id' => $category, 'name' => 'Imported item', 'unit' => 'UN', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $variant = DB::table('inventory_product_variants')->insertGetId(['item_id' => $item, 'description' => 'Legacy variant', 'identity_hash' => hash('sha256', 'import'), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['type', 'code', 'quantity', 'date', 'description', 'cost', 'service_order_number'], null, 'A1');
        $sheet->fromArray(['ENTRY', 'IMP01', 5, null, 'Synthetic entry', '12.50', null], null, 'A2');
        $sheet->fromArray(['ENTRY', 'IMP01', 3, '2003-05-30', 'Synthetic corrected date', null, '1001'], null, 'A3');
        $sheet->fromArray(['ISSUE', 'IMP01', null, null, 'Missing quantity', null, '500'], null, 'A4');
        $sheet->fromArray(['ENTRY', 'PEN02', 1, '2026-09-01', 'Legacy negative code', null, null], null, 'A5');
        $path = tempnam(sys_get_temp_dir(), 'inventory-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $storedPath = null;

        try {
            $upload = new UploadedFile($path, 'synthetic.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $response = $this->withoutMiddleware()->actingAs($user)->post('/api/v1/inventory/imports/analyze', ['file' => $upload], ['Accept' => 'application/json'])->assertCreated();
            $batch = $response->json('data');
            $storedPath = $batch['storage_path'];
            self::assertSame('NEEDS_REVIEW', $batch['status']);
            self::assertTrue(Storage::disk('inventory-imports')->exists($batch['storage_path']));
            $rows = $this->getJson('/api/v1/inventory/imports/'.$batch['id'])->assertOk()->json('rows.data');
            self::assertNull($rows[0]['source_payload']['date']);
            self::assertSame('SKIPPED', $rows[0]['resolution']);
            self::assertSame('2003-05-30', $rows[1]['source_payload']['date']);
            self::assertSame('2023-05-30', $rows[1]['corrected_payload']['date']);
            self::assertSame('2003-05-30', $rows[1]['corrected_payload']['original_date']);
            self::assertSame('SKIPPED', $rows[3]['resolution']);

            $this->patchJson('/api/v1/inventory/imports/'.$batch['id'].'/resolutions', [
                'version' => 1,
                'rows' => [
                    ['id' => $rows[1]['id'], 'action' => 'MAPPED', 'itemId' => $item, 'variantId' => $variant, 'type' => 'ENTRY'],
                ],
            ])->assertOk()->assertJsonPath('data.status', 'READY');
            $commit = $this->withHeader('Idempotency-Key', 'synthetic-import')->postJson('/api/v1/inventory/imports/'.$batch['id'].'/commit', ['version' => 2])
                ->assertOk()->assertJsonPath('data.status', 'IMPORTED');
            self::assertEqualsCanonicalizing([$rows[0]['id'], $rows[2]['id'], $rows[3]['id']], $commit->json('data.skippedRows'));
            self::assertSame(3, (int) DB::table('inventory_balances')->where('variant_id', $variant)->value('quantity'));
            self::assertSame(0, DB::table('inventory_ledger_entries')->where('variant_id', $variant)->whereNull('effective_on')->count());
            self::assertSame('2023-05-30', DB::table('inventory_ledger_entries')->where('variant_id', $variant)->whereNotNull('effective_on')->value('effective_on'));
            self::assertNull(DB::table('inventory_movement_lines')->where('variant_id', $variant)->value('unit_cost'));
            $this->withHeader('Idempotency-Key', 'synthetic-import')->postJson('/api/v1/inventory/imports/'.$batch['id'].'/commit', ['version' => 2])->assertOk();
            self::assertSame(1, DB::table('inventory_ledger_entries')->where('variant_id', $variant)->count());
        } finally {
            @unlink($path);
            if ($storedPath !== null) {
                Storage::disk('inventory-imports')->delete($storedPath);
            }
        }
    }

    private function userWithImportPermission(): User
    {
        $role = Role::query()->create(['slug' => 'import-'.bin2hex(random_bytes(3)), 'name' => 'Import operator']);
        $role->permissions()->sync(Permission::query()->whereIn('name', ['inventory.imports.execute', 'inventory.imports.view'])->pluck('id'));
        $user = User::query()->create(['name' => 'Importer', 'email' => 'import-'.bin2hex(random_bytes(4)).'@example.test']);
        $user->roles()->attach($role);

        return $user;
    }
}
