<?php

namespace Acme\Inventory\Http\Resources;

use Acme\Inventory\Models\InventoryProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryProductVariant */
final class VariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'itemId' => (string) $this->item_id, 'brand' => $this->brand, 'model' => $this->model, 'description' => $this->description, 'active' => $this->active, 'version' => $this->version, 'balances' => $this->whenLoaded('balances', fn () => $this->balances->map(fn ($balance) => ['locationId' => (string) $balance->location_id, 'quantity' => $balance->quantity, 'version' => $balance->version]))];
    }
}
