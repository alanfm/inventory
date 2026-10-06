<?php

namespace Acme\Inventory\Http\Controllers;

use Acme\Inventory\Application\Actions\ConfigureReplenishmentAction;
use Acme\Inventory\Application\Queries\ReplenishmentRecommendationQuery;
use Acme\Inventory\Models\InventoryItem;
use Illuminate\Http\Request;

final class ReplenishmentController
{
    public function show(Request $request, InventoryItem $item, ReplenishmentRecommendationQuery $query)
    {
        abort_unless($request->user()?->can('view', $item), 403);

        return response()->json(['data' => $query->forItem($item)]);
    }

    public function configure(Request $request, InventoryItem $item, ConfigureReplenishmentAction $action)
    {
        abort_unless($request->user()?->can('inventory.items.configureReplenishment'), 403);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'minimumStock' => ['present', 'nullable', 'integer', 'min:0'],
            'purchaseLeadTimeDays' => ['present', 'nullable', 'integer', 'min:0'],
            'safetyStock' => ['present', 'nullable', 'integer', 'min:0'],
            'recommendationWindowDays' => ['required', 'integer', 'between:30,365'],
        ]);
        $updated = $action->execute($item, $data, (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => ['id' => (string) $updated->id, 'version' => $updated->version,
            'minimumStock' => $updated->minimum_stock, 'purchaseLeadTimeDays' => $updated->purchase_lead_time_days,
            'safetyStock' => $updated->safety_stock, 'recommendationWindowDays' => $updated->recommendation_window_days]]);
    }
}
