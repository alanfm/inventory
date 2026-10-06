<?php

namespace Acme\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_benchmark_uses_synthetic_data_and_rolls_back(): void
    {
        $this->artisan('inventory:install')->assertSuccessful();

        $this->artisan('inventory:benchmark', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"rolledBack": true');

        self::assertSame(0, DB::table('inventory_items')->where('code', 'like', 'BEN%')->count());
        self::assertSame(0, DB::table('users')->where('email', 'like', 'inventory-benchmark-%')->count());
    }
}
