<?php

namespace Acme\Inventory\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class InventoryReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_and_stock_report_return_aggregated_inventory_and_enforce_permissions(): void
    {
        [$user, $item, $variant] = $this->fixture(['inventory.dashboard.view', 'inventory.reports.view']);
        DB::table('inventory_balances')->where('variant_id', $variant)->update(['quantity' => 4]);
        foreach ([['6.25', 2], [null, 1]] as [$cost, $quantity]) {
            $entry = DB::table('inventory_movements')->insertGetId(['type' => 'ENTRY', 'status' => 'POSTED', 'location_id' => DB::table('inventory_locations')->where('code', 'TI')->value('id'), 'occurred_on' => '2026-09-10', 'version' => 2, 'posted_by' => $user->id, 'posted_at' => '2026-09-10', 'created_by' => $user->id, 'created_at' => '2026-09-10', 'updated_at' => '2026-09-10']);
            DB::table('inventory_movement_lines')->insert(['movement_id' => $entry, 'variant_id' => $variant, 'quantity' => $quantity, 'unit_cost' => $cost, 'snapshot' => json_encode(['description' => 'Variante'], JSON_THROW_ON_ERROR), 'created_at' => '2026-09-10', 'updated_at' => '2026-09-10']);
        }
        $this->actingAs($user)->getJson('/api/v1/inventory/dashboard?from=2026-09-01&to=2026-09-29')
            ->assertOk()->assertJsonPath('data.activeItems', 1)->assertJsonPath('data.asOf', fn ($value) => is_string($value));
        $this->getJson('/api/v1/inventory/reports/stock?itemId='.$item)
            ->assertOk()->assertJsonPath('data.0.variantStock', 4)->assertJsonPath('data.0.aggregateStock', 4)
            ->assertJsonPath('data.0.minimumStock', 3)->assertJsonPath('data.0.knownEntryCostBRL', '12.50')
            ->assertJsonPath('data.0.unknownCostLines', 1)->assertJsonPath('data.0.unknownCostUnits', 1);
        $this->getJson('/api/v1/inventory/reports/unknown')->assertNotFound();

        $unauthorized = User::query()->create(['name' => 'Sem relatório', 'email' => 'reports-denied-'.bin2hex(random_bytes(4)).'@example.test']);
        $this->actingAs($unauthorized)->getJson('/api/v1/inventory/reports/stock')->assertForbidden();
    }

    public function test_dashboard_prioritizes_inconsistencies_and_groups_movements_with_inclusive_dates(): void
    {
        [$user, $item, $variant, $location] = $this->fixture(['inventory.dashboard.view']);
        DB::table('inventory_balances')->where('variant_id', $variant)->update(['quantity' => -1]);
        $other = DB::table('inventory_product_variants')->insertGetId([
            'item_id' => $item, 'brand' => 'Outra', 'description' => 'Outra variante',
            'identity_hash' => hash('sha256', 'other-'.$item), 'active' => true, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_balances')->insert(['location_id' => $location, 'variant_id' => $other, 'quantity' => 2, 'version' => 1]);
        $this->ledger($user->id, $location, $variant, 'ENTRY', '2026-09-01', 2, '');
        $this->ledger($user->id, $location, $variant, 'ISSUE', '2026-09-29', -1, '');
        $this->ledger($user->id, $location, $variant, 'ENTRY', '2026-08-31', 1, '');

        $this->actingAs($user)->getJson('/api/v1/inventory/dashboard?from=2026-09-01&to=2026-09-29')
            ->assertOk()->assertJsonPath('data.inconsistentItems', 1)
            ->assertJsonPath('data.replenishmentAlerts', 0)->assertJsonPath('data.attentionItems', 1)
            ->assertJsonPath('data.regularItems', 0)->assertJsonPath('data.alerts.0.situation', 'INCONSISTENT')
            ->assertJsonPath('data.alerts.0.stock', 1)->assertJsonPath('data.movementCount', 2)
            ->assertJsonCount(2, 'data.movementSeries')
            ->assertJsonPath('data.movementSeries.0.date', '2026-09-01')
            ->assertJsonPath('data.movementSeries.0.entries', 1)
            ->assertJsonPath('data.movementSeries.1.issues', 1);
        $this->getJson('/api/v1/inventory/dashboard?from=2026-09-02&to=2026-09-28')
            ->assertOk()->assertJsonPath('data.movementCount', 0)->assertJsonCount(0, 'data.movementSeries');
    }

    public function test_consumption_filters_inclusive_dates_groups_reversals_and_exports_safe_csv_and_xlsx(): void
    {
        [$user, $item, $variant, $location] = $this->fixture(['inventory.reports.view', 'inventory.reports.export']);
        $this->ledger($user->id, $location, $variant, 'ISSUE', '2026-09-01', -3, 'MAL-1');
        $this->ledger($user->id, $location, $variant, 'REVERSAL', '2026-09-29', 1, 'MAL-1', true);
        $this->ledger($user->id, $location, $variant, 'ISSUE', '2026-08-31', -9, 'OUTSIDE');

        $this->actingAs($user)->getJson('/api/v1/inventory/reports/consumption?from=2026-09-01&to=2026-09-29&groupBy=serviceOrderNumber')
            ->assertOk()->assertJsonPath('data.0.group', 'MAL-1')->assertJsonPath('data.0.grossIssues', 3)
            ->assertJsonPath('data.0.reversedIssues', 1)->assertJsonPath('data.0.netConsumption', 2);
        $csv = $this->get('/api/v1/inventory/exports/consumption?from=2026-09-01&to=2026-09-29&groupBy=serviceOrderNumber&format=csv')
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('generatedAt', $csv->getContent());
        $safeStockCsv = $this->get('/api/v1/inventory/exports/stock?format=csv')->assertOk();
        self::assertStringContainsString("'=SUM(1,1)", $safeStockCsv->getContent());

        $xlsx = $this->get('/api/v1/inventory/exports/stock?format=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringStartsWith('PK', $xlsx->getContent());
        $path = tempnam(sys_get_temp_dir(), 'inventory-xlsx-test-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $xlsx->getContent());
            $spreadsheet = IOFactory::load($path);
            self::assertSame(DataType::TYPE_STRING, $spreadsheet->getActiveSheet()->getCell('G2')->getDataType());
            self::assertSame('=SUM(1,1)', $spreadsheet->getActiveSheet()->getCell('G2')->getValue());
            $spreadsheet->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        self::assertSame(1, DB::table('inventory_items')->where('id', $item)->count());
    }

    public function test_export_requires_both_report_permissions_and_rejects_invalid_filters(): void
    {
        [$user] = $this->fixture(['inventory.reports.view']);
        $this->actingAs($user)->getJson('/api/v1/inventory/exports/stock?format=csv')->assertForbidden();
        $this->getJson('/api/v1/inventory/reports/stock?from=2026-10-02&to=2026-10-01')->assertUnprocessable();
    }

    public function test_replenishment_and_adjustment_reports_include_calculation_and_audit_context(): void
    {
        CarbonImmutable::setTestNow('2026-09-29 10:00:00');
        [$user, $item, $variant, $location] = $this->fixture(['inventory.reports.view']);
        $from = CarbonImmutable::now()->subDays(180)->toDateString();
        DB::table('inventory_items')->where('id', $item)->update([
            'history_coverage_from' => $from,
            'purchase_lead_time_days' => 30,
            'safety_stock' => 5,
        ]);
        $this->ledger($user->id, $location, $variant, 'ISSUE', CarbonImmutable::now()->subDays(60)->toDateString(), -90, 'OS-90');

        $this->actingAs($user)->getJson('/api/v1/inventory/reports/replenishment?itemId='.$item)
            ->assertOk()->assertJsonPath('data.0.recommendationStatus', 'CALCULABLE')
            ->assertJsonPath('data.0.consumption', 90)->assertJsonPath('data.0.suggestedMinimumStock', 20);

        $movement = DB::table('inventory_movements')->insertGetId(['type' => 'ADJUSTMENT', 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => '2026-09-20', 'reason' => 'Contagem conferida', 'version' => 2, 'posted_by' => $user->id, 'posted_at' => '2026-09-20', 'created_by' => $user->id, 'created_at' => '2026-09-20', 'updated_at' => '2026-09-20']);
        $line = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $movement, 'variant_id' => $variant, 'counted_quantity' => 0, 'snapshot' => json_encode(['description' => 'Variante'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $line, 'variant_id' => $variant, 'location_id' => $location, 'effective_on' => '2026-09-20', 'delta' => -2, 'posted_at' => '2026-09-20']);
        $this->getJson('/api/v1/inventory/reports/adjustments?from=2026-09-20&to=2026-09-20')
            ->assertOk()->assertJsonPath('data.0.delta', -2)->assertJsonPath('data.0.reason', 'Contagem conferida');
    }

    /** @param list<string> $permissions @return array{User, int, int, int} */
    private function fixture(array $permissions): array
    {
        $this->artisan('core:sync-permissions')->assertSuccessful();
        $this->artisan('inventory:install')->assertSuccessful();
        $role = Role::query()->create(['slug' => 'reports-'.bin2hex(random_bytes(3)), 'name' => 'Relatórios']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user = User::query()->create(['name' => 'Analista', 'email' => 'reports-'.bin2hex(random_bytes(4)).'@example.test']);
        $user->roles()->attach($role);
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Relatório', 'normalized_name' => 'rel-'.bin2hex(random_bytes(3)), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('inventory_items')->insertGetId(['code' => 'RPT-'.strtoupper(bin2hex(random_bytes(2))), 'category_id' => $category, 'name' => 'Item relatório', 'unit' => 'UN', 'minimum_stock' => 3, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $variant = DB::table('inventory_product_variants')->insertGetId(['item_id' => $item, 'brand' => '=SUM(1,1)', 'description' => 'Variante', 'identity_hash' => hash('sha256', (string) $item), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $location = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');
        DB::table('inventory_balances')->insert(['location_id' => $location, 'variant_id' => $variant, 'quantity' => 0, 'version' => 1]);

        return [$user, $item, $variant, $location];
    }

    private function ledger(int $actor, int $location, int $variant, string $type, string $date, int $delta, string $order, bool $reversal = false): void
    {
        $original = null;
        if ($reversal) {
            $original = DB::table('inventory_movements')->insertGetId(['type' => 'ISSUE', 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => $date, 'service_order_number' => $order, 'version' => 2, 'posted_by' => $actor, 'posted_at' => $date, 'created_by' => $actor, 'created_at' => $date, 'updated_at' => $date]);
        }
        $movement = DB::table('inventory_movements')->insertGetId(['type' => $type, 'status' => 'POSTED', 'location_id' => $location, 'occurred_on' => $date, 'service_order_number' => $reversal ? null : $order, 'reverses_movement_id' => $original, 'version' => 2, 'posted_by' => $actor, 'posted_at' => $date, 'created_by' => $actor, 'created_at' => $date, 'updated_at' => $date]);
        $line = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $movement, 'variant_id' => $variant, 'quantity' => abs($delta), 'snapshot' => json_encode(['description' => 'Variante'], JSON_THROW_ON_ERROR), 'created_at' => $date, 'updated_at' => $date]);
        DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $line, 'variant_id' => $variant, 'location_id' => $location, 'effective_on' => $date, 'delta' => $delta, 'posted_at' => $date]);
    }
}
