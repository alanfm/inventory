<?php

namespace Acme\Inventory\Application\Queries;

use Illuminate\Support\Facades\DB;

final class ItemLedgerQuery
{
    /** @return array{openingBalance: int, entries: list<array<string, mixed>>} */
    public function forVariant(int $variantId, int $locationId, ?string $from = null, ?string $to = null): array
    {
        $base = DB::table('inventory_ledger_entries as l')->join('inventory_movement_lines as ml', 'ml.id', '=', 'l.movement_line_id')->join('inventory_movements as m', 'm.id', '=', 'ml.movement_id')->where('l.variant_id', $variantId)->where('l.location_id', $locationId);
        $opening = (clone $base)->when($from !== null, fn ($query) => $query->where('l.effective_on', '<', $from))->sum('l.delta');
        $rows = (clone $base)->when($from !== null, fn ($query) => $query->where('l.effective_on', '>=', $from))->when($to !== null, fn ($query) => $query->where('l.effective_on', '<=', $to))->orderBy('l.effective_on')->orderBy('l.id')->get(['l.id', 'l.effective_on', 'l.delta', 'm.id as movement_id', 'm.type', 'm.service_order_number', 'm.document_number']);

        return ['openingBalance' => (int) $opening, 'entries' => $rows->map(static fn ($row): array => (array) $row)->all()];
    }
}
