<?php

namespace Acme\Inventory;

use Acme\Inventory\Models\InventoryCategory;
use Acme\Inventory\Models\InventoryItem;
use Acme\Inventory\Models\InventoryProductVariant;
use Acme\Inventory\Policies\InventoryCategoryPolicy;
use Acme\Inventory\Policies\InventoryItemPolicy;
use Acme\Inventory\Policies\InventoryVariantPolicy;
use App\Core\Modules\ModuleDescriptor;
use App\Core\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'inventory';
    }

    protected function registerBindings(ModuleDescriptor $module): void
    {
        Gate::policy(InventoryCategory::class, InventoryCategoryPolicy::class);
        Gate::policy(InventoryItem::class, InventoryItemPolicy::class);
        Gate::policy(InventoryProductVariant::class, InventoryVariantPolicy::class);
    }

    protected function bootModule(ModuleDescriptor $module): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\BenchmarkInventoryCommand::class,
                Console\InstallInventoryCommand::class,
                Console\ReconcileInventoryCommand::class,
            ]);
        }
    }
}
