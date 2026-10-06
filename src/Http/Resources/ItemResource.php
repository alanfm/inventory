<?php

namespace Acme\Inventory\Http\Resources;

use Acme\Inventory\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryItem */
final class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'code' => $this->code, 'name' => $this->name, 'description' => $this->description, 'unit' => $this->unit, 'active' => $this->active, 'version' => $this->version, 'category' => $this->whenLoaded('category', fn () => ['id' => (string) $this->category->id, 'name' => $this->category->name]), 'minimumStock' => $this->minimum_stock, 'purchaseLeadTimeDays' => $this->purchase_lead_time_days, 'safetyStock' => $this->safety_stock, 'recommendationWindowDays' => $this->recommendation_window_days, 'variants' => VariantResource::collection($this->whenLoaded('variants'))];
    }
}
