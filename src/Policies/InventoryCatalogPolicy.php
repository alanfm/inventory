<?php

namespace Acme\Inventory\Policies;

use Acme\Inventory\Models\InventoryCategory;
use Acme\Inventory\Models\InventoryItem;
use Acme\Inventory\Models\InventoryProductVariant;
use App\Models\User;

final class InventoryCatalogPolicy
{
    public function viewAnyCategory(User $user): bool
    {
        return $user->can('inventory.categories.viewAny');
    }

    public function viewCategory(User $user, InventoryCategory $category): bool
    {
        return $user->can('inventory.categories.viewAny');
    }

    public function viewAnyItem(User $user): bool
    {
        return $user->can('inventory.items.viewAny');
    }

    public function viewItem(User $user, InventoryItem $item): bool
    {
        return $user->can('inventory.items.view');
    }

    public function createCategory(User $user): bool
    {
        return $user->can('inventory.categories.create');
    }

    public function updateCategory(User $user, InventoryCategory $category): bool
    {
        return $user->can('inventory.categories.update');
    }

    public function createItem(User $user): bool
    {
        return $user->can('inventory.items.create');
    }

    public function updateItem(User $user, InventoryItem $item): bool
    {
        return $user->can('inventory.items.update');
    }

    public function createVariant(User $user): bool
    {
        return $user->can('inventory.variants.create');
    }

    public function updateVariant(User $user, InventoryProductVariant $variant): bool
    {
        return $user->can('inventory.variants.update');
    }
}
