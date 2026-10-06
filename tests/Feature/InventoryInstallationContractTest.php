<?php

namespace Acme\Inventory\Tests\Feature;

use Acme\Inventory\Database\Factories\InventoryCategoryFactory;
use Acme\Inventory\Models\InventoryCategory;
use App\Core\Modules\Actions\CheckModuleCompatibilityAction;
use App\Core\Modules\ModuleDiscovery;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleStateStore;
use Tests\TestCase;

final class InventoryInstallationContractTest extends TestCase
{
    public function test_package_root_passes_the_installer_preflight_on_a_clean_core(): void
    {
        $registry = new ModuleRegistry(
            new ModuleDiscovery([]),
            new ModuleStateStore(sys_get_temp_dir().'/inventory-preflight-'.bin2hex(random_bytes(8)).'.json'),
            coreVersion: '1.1.0',
            laravelVersion: app()->version(),
        );
        $manifest = (new CheckModuleCompatibilityAction($registry))->execute(dirname(__DIR__, 2));

        $this->assertSame('inventory', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('resources/spa/module.ts', $manifest->frontendEntry);
    }

    public function test_package_autoloads_the_factory_used_by_its_model(): void
    {
        $factory = InventoryCategory::factory();

        $this->assertInstanceOf(InventoryCategoryFactory::class, $factory);
        $category = $factory->make();
        $this->assertInstanceOf(InventoryCategory::class, $category);
        $this->assertSame(mb_strtolower(trim($category->name)), $category->normalized_name);
    }
}
