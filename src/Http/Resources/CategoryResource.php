<?php

namespace Acme\Inventory\Http\Resources;

use Acme\Inventory\Models\InventoryCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryCategory */
final class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name, 'observations' => $this->observations, 'active' => $this->active, 'version' => $this->version];
    }
}
