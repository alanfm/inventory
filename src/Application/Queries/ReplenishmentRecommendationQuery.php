<?php

namespace Acme\Inventory\Application\Queries;

use Acme\Inventory\Models\InventoryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReplenishmentRecommendationQuery
{
    /** @return array<string, mixed> */
    public function forItem(InventoryItem $item, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $today = $asOf->startOfDay();
        $window = (int) $item->recommendation_window_days;
        $to = $today->subDay();
        $from = $today->subDays($window);
        $balances = DB::table('inventory_balances as b')->join('inventory_product_variants as v', 'v.id', '=', 'b.variant_id')
            ->where('v.item_id', $item->id)->select('b.quantity')->get();
        $stock = (int) $balances->sum('quantity');
        $inconsistent = $balances->contains(static fn ($row): bool => (int) $row->quantity < 0);

        $situation = match (true) {
            $inconsistent => 'INCONSISTENT',
            $stock === 0 => 'OUT_OF_STOCK',
            $item->minimum_stock === null => 'NOT_CONFIGURED',
            $stock <= $item->minimum_stock => 'REPLENISHMENT',
            default => 'OK',
        };
        $base = [
            'itemId' => (string) $item->id, 'situation' => $situation, 'stock' => $stock,
            'minimumStock' => $item->minimum_stock, 'windowDays' => $window,
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'computedAt' => $asOf->toIso8601String(),
            'purchaseLeadTimeDays' => $item->purchase_lead_time_days, 'safetyStock' => $item->safety_stock,
            'itemVersion' => $item->version,
        ];
        if ($item->purchase_lead_time_days === null || $item->safety_stock === null) {
            return [...$base, 'status' => 'NOT_CALCULABLE', 'reason' => 'REPLENISHMENT_PARAMETERS_MISSING'];
        }
        if ($item->history_coverage_from === null || CarbonImmutable::parse($item->history_coverage_from)->gt($from)) {
            return [...$base, 'status' => 'INSUFFICIENT_HISTORY', 'reason' => 'HISTORY_DOES_NOT_COVER_WINDOW'];
        }

        $grossIssues = (int) DB::table('inventory_ledger_entries as l')
            ->join('inventory_movement_lines as ml', 'ml.id', '=', 'l.movement_line_id')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')
            ->join('inventory_product_variants as v', 'v.id', '=', 'l.variant_id')
            ->where('v.item_id', $item->id)->where('m.status', 'POSTED')->where('m.type', 'ISSUE')
            ->whereBetween('l.effective_on', [$from->toDateString(), $to->toDateString()])->where('l.delta', '<', 0)->sum('l.delta');
        $reversedIssues = (int) DB::table('inventory_ledger_entries as l')
            ->join('inventory_movement_lines as ml', 'ml.id', '=', 'l.movement_line_id')
            ->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')
            ->join('inventory_movements as original', 'original.id', '=', 'm.reverses_movement_id')
            ->join('inventory_product_variants as v', 'v.id', '=', 'l.variant_id')
            ->where('v.item_id', $item->id)->where('m.status', 'POSTED')->where('original.type', 'ISSUE')
            ->whereBetween('l.effective_on', [$from->toDateString(), $to->toDateString()])->where('l.delta', '>', 0)->sum('l.delta');
        $consumption = max(0, abs($grossIssues) - $reversedIssues);
        $average = $consumption / $window;
        $suggestion = (int) ceil($average * $item->purchase_lead_time_days + $item->safety_stock);

        return [...$base, 'status' => 'CALCULABLE', 'reason' => null, 'consumption' => $consumption,
            'averageDailyConsumption' => $average, 'suggestedMinimumStock' => $suggestion, 'historyCovered' => true];
    }
}
