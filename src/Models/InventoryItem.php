<?php

namespace Acme\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $category_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $unit
 * @property bool $active
 * @property int $version
 * @property int|null $minimum_stock
 * @property int|null $purchase_lead_time_days
 * @property int|null $safety_stock
 * @property int $recommendation_window_days
 * @property string|null $history_coverage_from
 */
final class InventoryItem extends Model
{
    protected $table = 'inventory_items';

    protected $fillable = ['code', 'category_id', 'name', 'description', 'unit', 'minimum_stock', 'purchase_lead_time_days', 'safety_stock', 'recommendation_window_days', 'history_coverage_from', 'active', 'version', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer', 'minimum_stock' => 'integer', 'purchase_lead_time_days' => 'integer', 'safety_stock' => 'integer', 'recommendation_window_days' => 'integer', 'history_coverage_from' => 'date'];
    }

    /** @return BelongsTo<InventoryCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    /** @return HasMany<InventoryProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(InventoryProductVariant::class, 'item_id');
    }
}
