<?php

namespace Acme\Inventory\Database\Factories;

use Acme\Inventory\Models\InventoryCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

final class InventoryCategoryFactory extends Factory
{
    protected $model = InventoryCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'normalized_name' => mb_strtolower(trim($name)),
            'active' => true,
            'version' => 1,
        ];
    }
}
