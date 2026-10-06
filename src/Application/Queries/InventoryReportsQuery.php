<?php

namespace Acme\Inventory\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class InventoryReportsQuery
{
    /** @return list<array<string, mixed>> */
    public function rows(string $type, array $filters): array
    {
        return DB::transaction(fn (): array => match ($type) {
            'stock' => $this->stock($filters),
            'replenishment' => $this->replenishment($filters),
            'consumption' => $this->consumption($filters),
            'adjustments' => $this->adjustments($filters),
            default => [],
        });
    }

    /** @return list<array<string, mixed>> */
    private function stock(array $filters): array
    {
        $rows = DB::table('inventory_items as i')->join('inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('inventory_product_variants as v', 'v.item_id', '=', 'i.id')
            ->leftJoin('inventory_balances as b', 'b.variant_id', '=', 'v.id')
            ->when($filters['itemId'] ?? null, fn ($q, $id) => $q->where('i.id', $id))
            ->when($filters['categoryId'] ?? null, fn ($q, $id) => $q->where('i.category_id', $id))
            ->groupBy('i.id', 'i.code', 'i.name', 'i.unit', 'i.minimum_stock', 'c.name', 'v.id', 'v.brand', 'v.model', 'v.description')
            ->orderBy('i.code')->orderBy('v.id')->get([
                'i.id as item_id', 'i.code', 'i.name as item_name', 'i.unit', 'i.minimum_stock', 'c.name as category',
                'v.id as variant_id', 'v.brand', 'v.model', 'v.description as variant_description',
                DB::raw('COALESCE(SUM(b.quantity), 0) as variant_stock'),
                DB::raw('SUM(CASE WHEN b.quantity < 0 THEN 1 ELSE 0 END) as negative_balance'),
            ]);
        $totals = $rows->groupBy('item_id')->map(static fn ($itemRows): int => (int) $itemRows->sum('variant_stock'));
        $variantIds = $rows->pluck('variant_id')->filter()->all();
        $costs = $variantIds === [] ? collect() : DB::table('inventory_movement_lines as ml')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')
            ->whereIn('ml.variant_id', $variantIds)->where('m.status', 'POSTED')->where('m.type', 'ENTRY')
            ->groupBy('ml.variant_id')->get(['ml.variant_id', DB::raw('CAST(SUM(CASE WHEN ml.unit_cost IS NOT NULL THEN ml.quantity * ml.unit_cost ELSE 0 END) AS DECIMAL(15,2)) as known_entry_cost'), DB::raw('SUM(CASE WHEN ml.unit_cost IS NULL THEN 1 ELSE 0 END) as unknown_cost_lines'), DB::raw('SUM(CASE WHEN ml.unit_cost IS NULL THEN ml.quantity ELSE 0 END) as unknown_cost_units')])->keyBy('variant_id');

        return $rows->map(static function ($row) use ($totals, $costs): array {
            $aggregate = $totals->get($row->item_id, 0);
            $variantCost = $row->variant_id === null ? null : $costs->get($row->variant_id);
            $situation = (int) $row->negative_balance > 0 ? 'INCONSISTENT' : ($aggregate === 0 ? 'OUT_OF_STOCK' : ($row->minimum_stock === null ? 'NOT_CONFIGURED' : ($aggregate <= $row->minimum_stock ? 'REPLENISHMENT' : 'OK')));

            return ['itemId' => (string) $row->item_id, 'code' => $row->code, 'item' => $row->item_name, 'category' => $row->category,
                'unit' => $row->unit, 'variantId' => $row->variant_id === null ? null : (string) $row->variant_id,
                'brand' => $row->brand, 'model' => $row->model, 'variant' => $row->variant_description,
                'variantStock' => (int) $row->variant_stock, 'aggregateStock' => $aggregate,
                'minimumStock' => $row->minimum_stock, 'situation' => $situation,
                'knownEntryCostBRL' => $variantCost === null ? '0.00' : (string) $variantCost->known_entry_cost,
                'unknownCostLines' => $variantCost === null ? 0 : (int) $variantCost->unknown_cost_lines,
                'unknownCostUnits' => $variantCost === null ? 0 : (int) $variantCost->unknown_cost_units];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function replenishment(array $filters): array
    {
        $items = DB::table('inventory_items as i')->join('inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('inventory_product_variants as v', 'v.item_id', '=', 'i.id')
            ->leftJoin('inventory_balances as b', 'b.variant_id', '=', 'v.id')
            ->when($filters['itemId'] ?? null, fn ($q, $id) => $q->where('i.id', $id))
            ->when($filters['categoryId'] ?? null, fn ($q, $id) => $q->where('i.category_id', $id))
            ->groupBy('i.id', 'i.code', 'i.name', 'i.unit', 'i.minimum_stock', 'i.purchase_lead_time_days', 'i.safety_stock', 'i.recommendation_window_days', 'i.history_coverage_from', 'c.name')
            ->orderBy('i.code')->get(['i.id', 'i.code', 'i.name', 'i.unit', 'i.minimum_stock', 'i.purchase_lead_time_days', 'i.safety_stock', 'i.recommendation_window_days', 'i.history_coverage_from', 'c.name as category', DB::raw('COALESCE(SUM(b.quantity), 0) as stock'), DB::raw('SUM(CASE WHEN b.quantity < 0 THEN 1 ELSE 0 END) as negative_variants')]);
        if ($items->isEmpty()) {
            return [];
        }

        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $maxWindow = (int) $items->max('recommendation_window_days');
        $start = $today->subDays($maxWindow)->toDateString();
        $end = $today->subDay()->toDateString();
        $ledger = DB::table('inventory_ledger_entries as l')->join('inventory_movement_lines as ml', 'ml.id', '=', 'l.movement_line_id')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')->join('inventory_product_variants as v', 'v.id', '=', 'l.variant_id')
            ->leftJoin('inventory_movements as original', 'original.id', '=', 'm.reverses_movement_id')
            ->whereIn('v.item_id', $items->pluck('id'))->where('m.status', 'POSTED')->whereBetween('l.effective_on', [$start, $end])
            ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('m.type', 'ISSUE')->where('l.delta', '<', 0))->orWhere(fn ($q2) => $q2->where('m.type', 'REVERSAL')->where('original.type', 'ISSUE')->where('l.delta', '>', 0)))
            ->get(['v.item_id', 'l.effective_on', 'l.delta', 'm.type']);

        return $items->map(static function ($item) use ($ledger, $today): array {
            $window = (int) $item->recommendation_window_days;
            $from = $today->subDays($window)->toDateString();
            $to = $today->subDay()->toDateString();
            $consumption = max(0, (int) abs($ledger->where('item_id', $item->id)->where('type', 'ISSUE')->whereBetween('effective_on', [$from, $to])->sum('delta')) - (int) $ledger->where('item_id', $item->id)->where('type', 'REVERSAL')->whereBetween('effective_on', [$from, $to])->sum('delta'));
            $suggestion = null;
            $status = $item->purchase_lead_time_days === null || $item->safety_stock === null ? 'NOT_CALCULABLE' : 'CALCULABLE';
            if ($status === 'CALCULABLE' && ($item->history_coverage_from === null || $item->history_coverage_from > $from)) {
                $status = 'INSUFFICIENT_HISTORY';
            }
            if ($status === 'CALCULABLE') {
                $suggestion = (int) ceil(($consumption / $window) * $item->purchase_lead_time_days + $item->safety_stock);
            }
            $situation = (int) $item->negative_variants > 0 ? 'INCONSISTENT' : ((int) $item->stock === 0 ? 'OUT_OF_STOCK' : ($item->minimum_stock === null ? 'NOT_CONFIGURED' : ((int) $item->stock <= $item->minimum_stock ? 'REPLENISHMENT' : 'OK')));

            return ['itemId' => (string) $item->id, 'code' => $item->code, 'item' => $item->name, 'category' => $item->category, 'unit' => $item->unit,
                'stock' => (int) $item->stock, 'minimumStock' => $item->minimum_stock, 'situation' => $situation,
                'periodFrom' => $from, 'periodTo' => $to, 'consumption' => $status === 'CALCULABLE' ? $consumption : null,
                'purchaseLeadTimeDays' => $item->purchase_lead_time_days, 'safetyStock' => $item->safety_stock,
                'suggestedMinimumStock' => $suggestion, 'recommendationStatus' => $status];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function consumption(array $filters): array
    {
        $group = $filters['groupBy'] ?? 'item';
        $groupSelect = match ($group) {
            'category' => ['c.name as groupName', 'c.id as groupId'],
            'serviceOrderNumber' => [DB::raw("COALESCE(original.service_order_number, m.service_order_number, '') as groupName"), DB::raw('NULL as groupId')],
            'month' => [DB::raw("DATE_FORMAT(l.effective_on, '%Y-%m') as groupName"), DB::raw('NULL as groupId')],
            default => ['i.code as groupName', 'i.id as groupId'],
        };
        $groupBy = match ($group) {
            'category' => ['c.id', 'c.name'], 'serviceOrderNumber' => [DB::raw("COALESCE(original.service_order_number, m.service_order_number, '')")], 'month' => [DB::raw("DATE_FORMAT(l.effective_on, '%Y-%m')")], default => ['i.id', 'i.code']
        };

        return DB::table('inventory_ledger_entries as l')->join('inventory_movement_lines as ml', 'ml.id', '=', 'l.movement_line_id')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')->join('inventory_product_variants as v', 'v.id', '=', 'l.variant_id')
            ->join('inventory_items as i', 'i.id', '=', 'v.item_id')->join('inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('inventory_movements as original', 'original.id', '=', 'm.reverses_movement_id')
            ->where('m.status', 'POSTED')->whereBetween('l.effective_on', [$filters['from'], $filters['to']])
            ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('m.type', 'ISSUE')->where('l.delta', '<', 0))->orWhere(fn ($q2) => $q2->where('m.type', 'REVERSAL')->where('original.type', 'ISSUE')->where('l.delta', '>', 0)))
            ->when($filters['itemId'] ?? null, fn ($q, $id) => $q->where('i.id', $id))->when($filters['categoryId'] ?? null, fn ($q, $id) => $q->where('i.category_id', $id))
            ->groupBy($groupBy)->orderBy('groupName')->get([...$groupSelect, DB::raw("SUM(CASE WHEN m.type = 'ISSUE' THEN ABS(l.delta) ELSE 0 END) as grossIssues"), DB::raw("SUM(CASE WHEN m.type = 'REVERSAL' THEN l.delta ELSE 0 END) as reversedIssues")])
            ->map(static fn ($row): array => ['groupBy' => $group, 'groupId' => $row->groupId, 'group' => $row->groupName,
                'grossIssues' => (int) $row->grossIssues, 'reversedIssues' => (int) $row->reversedIssues, 'netConsumption' => max(0, (int) $row->grossIssues - (int) $row->reversedIssues)])->all();
    }

    /** @return list<array<string, mixed>> */
    private function adjustments(array $filters): array
    {
        return DB::table('inventory_ledger_entries as l')->join('inventory_movement_lines as ml', 'ml.id', '=', 'l.movement_line_id')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')->join('inventory_product_variants as v', 'v.id', '=', 'l.variant_id')
            ->join('inventory_items as i', 'i.id', '=', 'v.item_id')->join('inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('inventory_movements as original', 'original.id', '=', 'm.reverses_movement_id')
            ->where('m.status', 'POSTED')->whereIn('m.type', ['ADJUSTMENT', 'REVERSAL'])->whereBetween('l.effective_on', [$filters['from'], $filters['to']])
            ->when($filters['itemId'] ?? null, fn ($q, $id) => $q->where('i.id', $id))->when($filters['categoryId'] ?? null, fn ($q, $id) => $q->where('i.category_id', $id))
            ->orderBy('l.effective_on')->orderBy('l.id')->get(['l.id', 'l.effective_on', 'l.delta', 'm.id as movement_id', 'm.type', 'm.reason', 'm.reverses_movement_id', 'original.id as original_id', 'i.code', 'i.name as item', 'i.unit', 'c.name as category', 'v.brand', 'v.model', 'm.posted_by'])
            ->map(static fn ($row): array => ['date' => $row->effective_on, 'movementId' => (string) $row->movement_id, 'type' => $row->type,
                'originalMovementId' => $row->original_id === null ? null : (string) $row->original_id, 'code' => $row->code, 'item' => $row->item,
                'category' => $row->category, 'unit' => $row->unit, 'brand' => $row->brand, 'model' => $row->model,
                'delta' => (int) $row->delta, 'reason' => $row->reason, 'actorId' => $row->posted_by === null ? null : (string) $row->posted_by])->all();
    }
}
