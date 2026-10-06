<?php

namespace Acme\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $item_id
 * @property string|null $brand
 * @property string|null $model
 * @property string $description
 * @property string $identity_hash
 * @property bool $active
 * @property int $version
 */
final class InventoryProductVariant extends Model
{
    protected $table = 'inventory_product_variants';

    protected $fillable = ['item_id', 'brand', 'model', 'description', 'identity_hash', 'active', 'version'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    /** @return HasMany<InventoryBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class, 'variant_id');
    }
}
