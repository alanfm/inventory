<?php

namespace Acme\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $location_id
 * @property int $variant_id
 * @property int $quantity
 * @property int $version
 */
final class InventoryBalance extends Model
{
    public $timestamps = false;

    protected $table = 'inventory_balances';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<InventoryProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryProductVariant::class, 'variant_id');
    }
}
