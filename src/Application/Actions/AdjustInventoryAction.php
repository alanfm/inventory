<?php

namespace Acme\Inventory\Application\Actions;

use Illuminate\Support\Facades\DB;

final class AdjustInventoryAction
{
    /** @return array<string, mixed> */
    public function execute(int $actorId, int $locationId, int $variantId, int $countedQuantity, int $expectedBalanceVersion, string $reason, string $key): array
    {
        $payload = compact('locationId', 'variantId', 'countedQuantity', 'expectedBalanceVersion', 'reason');

        return app(ExecuteIdempotentOperation::class)->execute($actorId, 'inventory.adjust', (string) $variantId, $key, $payload, fn (): array => $this->adjust($actorId, $locationId, $variantId, $countedQuantity, $expectedBalanceVersion, $reason));
    }

    /** @return array<string, mixed> */
    private function adjust(int $actorId, int $locationId, int $variantId, int $countedQuantity, int $expectedBalanceVersion, string $reason): array
    {
        return DB::transaction(function () use ($actorId, $locationId, $variantId, $countedQuantity, $expectedBalanceVersion, $reason): array {
            $itemId = DB::table('inventory_product_variants')->where('id', $variantId)->value('item_id');
            abort_if($itemId === null, 404);
            $item = DB::table('inventory_items')->where('id', $itemId)->lockForUpdate()->first();
            $variant = DB::table('inventory_product_variants')->where('id', $variantId)->lockForUpdate()->first();
            abort_if($item === null || $variant === null, 404);
            DB::table('inventory_balances')->insertOrIgnore(['location_id' => $locationId, 'variant_id' => $variantId, 'quantity' => 0, 'version' => 1]);
            $balance = DB::table('inventory_balances')->where(['location_id' => $locationId, 'variant_id' => $variantId])->lockForUpdate()->first();
            abort_if($balance === null, 409, 'BALANCE_UNAVAILABLE');
            abort_unless((int) $balance->version === $expectedBalanceVersion, 409, 'BALANCE_CHANGED');
            $delta = $countedQuantity - (int) $balance->quantity;
            $now = now();
            $movementId = DB::table('inventory_movements')->insertGetId(['type' => 'ADJUSTMENT', 'status' => 'POSTED', 'location_id' => $locationId, 'occurred_on' => $now->toDateString(), 'description' => 'Contagem física', 'reason' => $reason, 'version' => 1, 'posted_by' => $actorId, 'posted_at' => $now, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
            $lineId = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $movementId, 'variant_id' => $variantId, 'quantity' => null, 'counted_quantity' => $countedQuantity, 'snapshot' => json_encode(['code' => $item->code, 'itemName' => $item->name, 'unit' => $item->unit, 'brand' => $variant->brand, 'model' => $variant->model, 'description' => $variant->description], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            if ($delta !== 0) {
                DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $lineId, 'variant_id' => $variantId, 'location_id' => $locationId, 'effective_on' => $now->toDateString(), 'delta' => $delta, 'posted_at' => $now]);
                DB::table('inventory_balances')->where('id', $balance->id)->update(['quantity' => $countedQuantity, 'version' => $expectedBalanceVersion + 1, 'updated_at' => $now]);
            }
            DB::table('inventory_audit_events')->insert(['entity_type' => 'movement', 'entity_id' => $movementId, 'action' => $delta === 0 ? 'inventory_count_checked' : 'inventory_adjusted', 'actor_id' => $actorId, 'before' => json_encode(['quantity' => (int) $balance->quantity, 'version' => $expectedBalanceVersion]), 'after' => json_encode(['countedQuantity' => $countedQuantity, 'delta' => $delta]), 'reason' => $reason, 'occurred_at' => $now]);

            return ['movementId' => $movementId, 'variantId' => $variantId, 'previousQuantity' => (int) $balance->quantity, 'countedQuantity' => $countedQuantity, 'delta' => $delta, 'balanceVersion' => $delta === 0 ? $expectedBalanceVersion : $expectedBalanceVersion + 1];
        }, 3);
    }
}
