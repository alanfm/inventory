<?php

namespace Acme\Inventory\Application\Queries;

use Illuminate\Support\Facades\DB;

final class BalanceQuery
{
    /** @return list<array{variantId: int, locationId: int, quantity: int}> */
    public function forItem(int $itemId, int $locationId): array
    {
        return DB::table('inventory_product_variants as v')->leftJoin('inventory_balances as b', function ($join) use ($locationId): void {
            $join->on('b.variant_id', '=', 'v.id')->where('b.location_id', '=', $locationId);
        })->where('v.item_id', $itemId)->orderBy('v.id')->get(['v.id as variant_id', 'b.location_id', 'b.quantity'])->map(static fn ($row): array => [
            'variantId' => (int) $row->variant_id, 'locationId' => $locationId, 'quantity' => (int) ($row->quantity ?? 0),
        ])->all();
    }

    public function forVariant(int $variantId, int $locationId): int
    {
        return (int) DB::table('inventory_balances')->where(['variant_id' => $variantId, 'location_id' => $locationId])->value('quantity');
    }
}
