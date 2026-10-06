<?php

namespace Acme\Inventory\Application\Actions;

use Acme\Inventory\Models\InventoryItem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConfigureReplenishmentAction
{
    /** @param array{version:int,minimumStock:?int,purchaseLeadTimeDays:?int,safetyStock:?int,recommendationWindowDays:int} $data */
    public function execute(InventoryItem $item, array $data, int $actorId): InventoryItem
    {
        return DB::transaction(function () use ($item, $data, $actorId): InventoryItem {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($locked->version !== $data['version']) {
                throw new ConflictHttpException('VERSION_CONFLICT: O item foi alterado. Atualize e tente novamente.');
            }
            $before = $locked->only(['minimum_stock', 'purchase_lead_time_days', 'safety_stock', 'recommendation_window_days']);
            $locked->fill([
                'minimum_stock' => $data['minimumStock'],
                'purchase_lead_time_days' => $data['purchaseLeadTimeDays'],
                'safety_stock' => $data['safetyStock'],
                'recommendation_window_days' => $data['recommendationWindowDays'],
                'version' => $locked->version + 1,
                'updated_by' => $actorId,
            ])->save();
            $after = $locked->only(['minimum_stock', 'purchase_lead_time_days', 'safety_stock', 'recommendation_window_days']);
            DB::table('inventory_audit_events')->insert([
                'entity_type' => 'item', 'entity_id' => $locked->id, 'action' => 'replenishment_configured',
                'actor_id' => $actorId, 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
