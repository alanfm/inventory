<?php

namespace Acme\Inventory\Tests;

use Tests\TestCase;

final class ContractDiscoveryTest extends TestCase
{
    public function test_module_suite_is_discovered_by_the_host(): void
    {
        $this->assertSame('1.1.0', config('modules.core_version'));
    }
}
