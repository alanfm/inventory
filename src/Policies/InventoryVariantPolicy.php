<?php

namespace Acme\Inventory\Policies;

use Acme\Inventory\Models\InventoryProductVariant;
use App\Models\User;

final class InventoryVariantPolicy
{
    public function create(User $user): bool
    {
        return $user->can('inventory.variants.create');
    }

    public function update(User $user, InventoryProductVariant $variant): bool
    {
        return $user->can('inventory.variants.update');
    }
}
