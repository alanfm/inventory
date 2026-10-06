<?php

namespace Acme\Inventory\Application\Queries;

use Illuminate\Support\Facades\DB;

final class DashboardQuery
{
    /** @return array<string, mixed> */
    public function execute(?string $from = null, ?string $to = null): array
    {
        return DB::transaction(function () use ($from, $to): array {
            $today = now(config('app.timezone'))->toDateString();
            $from ??= now(config('app.timezone'))->startOfMonth()->toDateString();
            $to ??= $today;
            $items = DB::table('inventory_items as i')->leftJoin('inventory_categories as c', 'c.id', '=', 'i.category_id')
                ->leftJoin('inventory_product_variants as v', 'v.item_id', '=', 'i.id')
                ->leftJoin('inventory_balances as b', 'b.variant_id', '=', 'v.id')
                ->groupBy('i.id', 'i.code', 'i.name', 'i.unit', 'i.minimum_stock', 'i.active')
                ->get(['i.id', 'i.code', 'i.name', 'i.unit', 'i.minimum_stock', 'i.active', DB::raw('COALESCE(SUM(b.quantity), 0) as stock'), DB::raw('SUM(CASE WHEN b.quantity < 0 THEN 1 ELSE 0 END) as negative_variants')]);

            $active = $items->where('active', true);
            $movementBase = DB::table('inventory_ledger_entries as l')->join('inventory_movement_lines as ml', 'ml.id', '=', 'l.movement_line_id')
                ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')->where('m.status', 'POSTED')
                ->whereBetween('l.effective_on', [$from, $to]);

            $situations = $active->map(function ($row): array {
                $situation = match (true) {
                    (int) $row->negative_variants > 0 => 'INCONSISTENT',
                    (int) $row->stock === 0 => 'OUT_OF_STOCK',
                    $row->minimum_stock === null => 'NOT_CONFIGURED',
                    $row->stock <= $row->minimum_stock => 'REPLENISHMENT',
                    default => 'OK',
                };

                return ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name,
                    'unit' => $row->unit, 'stock' => (int) $row->stock,
                    'minimumStock' => $row->minimum_stock === null ? null : (int) $row->minimum_stock,
                    'situation' => $situation];
            });
            $counts = $situations->countBy('situation');
            $priority = ['INCONSISTENT' => 0, 'OUT_OF_STOCK' => 1, 'REPLENISHMENT' => 2, 'NOT_CONFIGURED' => 3];
            $movements = DB::table('inventory_movements')->whereIn('status', ['POSTED', 'REVERSED'])
                ->whereBetween('occurred_on', [$from, $to])
                ->groupBy('occurred_on', 'type')->orderBy('occurred_on')
                ->get(['occurred_on', 'type', DB::raw('COUNT(*) as total')]);

            return [
                'asOf' => now()->toIso8601String(), 'period' => ['from' => $from, 'to' => $to],
                'activeItems' => $active->count(),
                'inconsistentItems' => $counts->get('INCONSISTENT', 0),
                'outOfStockItems' => $counts->get('OUT_OF_STOCK', 0),
                'itemsWithoutMinimum' => $counts->get('NOT_CONFIGURED', 0),
                'replenishmentAlerts' => $counts->get('REPLENISHMENT', 0),
                'regularItems' => $counts->get('OK', 0),
                'attentionItems' => $situations->where('situation', '!=', 'OK')->count(),
                'alerts' => $situations->where('situation', '!=', 'OK')
                    ->sortBy(static fn (array $row): int => $priority[$row['situation']])->take(8)->values()->all(),
                'movementCount' => (int) $movements->sum('total'),
                'movementSeries' => $movements->groupBy('occurred_on')->map(static function ($rows, $date): array {
                    $byType = $rows->keyBy('type');

                    return ['date' => (string) $date, 'entries' => (int) ($byType->get('ENTRY')->total ?? 0),
                        'issues' => (int) ($byType->get('ISSUE')->total ?? 0),
                        'adjustments' => (int) ($byType->get('ADJUSTMENT')->total ?? 0),
                        'reversals' => (int) ($byType->get('REVERSAL')->total ?? 0)];
                })->values()->all(),
                'entryUnits' => (int) (clone $movementBase)->where('m.type', 'ENTRY')->where('l.delta', '>', 0)->sum('l.delta'),
                'issueUnits' => (int) abs((clone $movementBase)->where('m.type', 'ISSUE')->where('l.delta', '<', 0)->sum('l.delta')),
            ];
        });
    }
}
