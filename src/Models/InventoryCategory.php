<?php

namespace Acme\Inventory\Models;

use Acme\Inventory\Database\Factories\InventoryCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $observations
 * @property string $normalized_name
 * @property bool $active
 * @property int $version
 */
final class InventoryCategory extends Model
{
    /** @use HasFactory<InventoryCategoryFactory> */
    use HasFactory;

    protected $table = 'inventory_categories';

    protected $fillable = ['name', 'observations', 'normalized_name', 'active', 'version'];

    protected static function newFactory(): InventoryCategoryFactory
    {
        return InventoryCategoryFactory::new();
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }
}
