<?php

namespace Acme\Inventory\Application\Actions;

use Acme\Inventory\Domain\Exceptions\InsufficientStock;
use Illuminate\Support\Facades\DB;

final class ReverseMovementAction
{
    /** @return array<string, mixed> */
    public function execute(int $movementId, int $actorId, string $reason, string $key): array
    {
        return app(ExecuteIdempotentOperation::class)->execute($actorId, 'movement.reverse', (string) $movementId, $key, compact('movementId', 'reason'), fn (): array => $this->reverse($movementId, $actorId, $reason));
    }

    /** @return array<string, mixed> */
    private function reverse(int $movementId, int $actorId, string $reason): array
    {
        return DB::transaction(function () use ($movementId, $actorId, $reason): array {
            $original = DB::table('inventory_movements')->where('id', $movementId)->lockForUpdate()->first();
            abort_if($original === null, 404);
            abort_unless($original->status === 'POSTED' && $original->type !== 'REVERSAL', 409, 'MOVEMENT_NOT_REVERSIBLE');
            if (DB::table('inventory_movements')->where('reverses_movement_id', $movementId)->exists()) {
                abort(409, 'MOVEMENT_ALREADY_REVERSED');
            }
            $originalLines = DB::table('inventory_movement_lines as ml')->join('inventory_ledger_entries as l', 'l.movement_line_id', '=', 'ml.id')->where('ml.movement_id', $movementId)->orderBy('l.variant_id')->get(['ml.*', 'l.variant_id as ledger_variant_id', 'l.location_id as ledger_location_id', 'l.delta as ledger_delta']);
            abort_if($originalLines->isEmpty(), 409, 'MOVEMENT_HAS_NO_LEDGER');
            $variantIds = $originalLines->pluck('ledger_variant_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $variants = DB::table('inventory_product_variants')->whereIn('id', $variantIds)->orderBy('item_id')->orderBy('id')->get();
            $itemIds = $variants->pluck('item_id')->unique()->sort()->values()->all();
            DB::table('inventory_items')->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($variantIds as $variantId) {
                DB::table('inventory_balances')->insertOrIgnore(['location_id' => $original->location_id, 'variant_id' => $variantId, 'quantity' => 0, 'version' => 1]);
            }
            $balances = DB::table('inventory_balances')->where('location_id', $original->location_id)->whereIn('variant_id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('variant_id');
            foreach ($originalLines as $line) {
                $balance = $balances->get($line->ledger_variant_id);
                $delta = -(int) $line->ledger_delta;
                if ($balance === null || (int) $balance->quantity + $delta < 0) {
                    throw new InsufficientStock((int) $line->ledger_variant_id, (int) ($balance->quantity ?? 0), abs($delta));
                }
            }
            $now = now();
            $reversalId = DB::table('inventory_movements')->insertGetId(['type' => 'REVERSAL', 'status' => 'POSTED', 'location_id' => $original->location_id, 'occurred_on' => $now->toDateString(), 'description' => 'Estorno integral do movimento '.$movementId, 'reason' => $reason, 'version' => 1, 'reverses_movement_id' => $movementId, 'posted_by' => $actorId, 'posted_at' => $now, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($originalLines as $line) {
                $delta = -(int) $line->ledger_delta;
                $lineId = DB::table('inventory_movement_lines')->insertGetId(['movement_id' => $reversalId, 'variant_id' => $line->ledger_variant_id, 'quantity' => $line->quantity, 'unit_cost' => $line->unit_cost, 'snapshot' => $line->snapshot, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $lineId, 'variant_id' => $line->ledger_variant_id, 'location_id' => $line->ledger_location_id, 'effective_on' => $now->toDateString(), 'delta' => $delta, 'posted_at' => $now]);
                $balance = $balances->get($line->ledger_variant_id);
                DB::table('inventory_balances')->where('id', $balance->id)->update(['quantity' => (int) $balance->quantity + $delta, 'version' => (int) $balance->version + 1, 'updated_at' => $now]);
                $balances->put($line->ledger_variant_id, (object) [...(array) $balance, 'quantity' => (int) $balance->quantity + $delta]);
            }
            DB::table('inventory_movements')->where('id', $movementId)->update(['status' => 'REVERSED', 'version' => (int) $original->version + 1, 'updated_at' => $now]);
            foreach ([[$movementId, 'movement_reversed'], [$reversalId, 'reversal_posted']] as [$entityId, $event]) {
                DB::table('inventory_audit_events')->insert(['entity_type' => 'movement', 'entity_id' => $entityId, 'action' => $event, 'actor_id' => $actorId, 'before' => json_encode(['status' => 'POSTED']), 'after' => json_encode(['status' => $entityId === $movementId ? 'REVERSED' : 'POSTED']), 'reason' => $reason, 'occurred_at' => $now]);
            }

            return ['movementId' => $movementId, 'reversalId' => $reversalId, 'status' => 'REVERSED'];
        }, 3);
    }
}
