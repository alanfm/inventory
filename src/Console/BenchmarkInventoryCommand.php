<?php

namespace Acme\Inventory\Console;

use Acme\Inventory\Application\Actions\PostMovementAction;
use Acme\Inventory\Application\Queries\DashboardQuery;
use Acme\Inventory\Application\Queries\InventoryReportsQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BenchmarkInventoryCommand extends Command
{
    protected $signature = 'inventory:benchmark
        {--iterations=5 : Number of samples per operation}
        {--lines=100 : Number of lines in the confirmation sample}
        {--json : Emit machine-readable output}';

    protected $description = 'Measure synthetic Inventory reads and a 100-line confirmation without committing data';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('inventory:benchmark só pode ser executado em local ou testing.');
        }

        $iterations = max(1, (int) $this->option('iterations'));
        $lines = max(1, min(100, (int) $this->option('lines')));

        DB::beginTransaction();
        try {
            $transaction = [
                'fixture' => $this->createFixture($lines),
            ];
            $fixture = $transaction['fixture'];
            $transaction['operations'] = [
                'dashboard' => $this->measure($iterations, fn (): mixed => app(DashboardQuery::class)->execute('2026-01-01', '2026-12-31')),
                'stockReport' => $this->measure($iterations, fn (): mixed => app(InventoryReportsQuery::class)->rows('stock', [])),
                'postConfirmation' => $this->measure($iterations, function () use ($fixture): mixed {
                    $movementId = DB::table('inventory_movements')->insertGetId([
                        'type' => 'ENTRY',
                        'status' => 'DRAFT',
                        'location_id' => $fixture['locationId'],
                        'occurred_on' => now()->toDateString(),
                        'origin' => 'PURCHASE',
                        'document_number' => 'BENCHMARK',
                        'version' => 1,
                        'created_by' => $fixture['actorId'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $now = now();
                    DB::table('inventory_movement_lines')->insert(array_map(
                        static fn (int $variantId): array => [
                            'movement_id' => $movementId,
                            'variant_id' => $variantId,
                            'quantity' => 1,
                            'snapshot' => json_encode(['description' => 'Benchmark'], JSON_THROW_ON_ERROR),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $fixture['variantIds'],
                    ));

                    return app(PostMovementAction::class)->execute($movementId, $fixture['actorId'], 1, 'benchmark-'.$movementId);
                }),
            ];
        } finally {
            DB::rollBack();
        }
        $rolledBack = ! DB::table('users')->where('email', $transaction['fixture']['marker'])->exists();

        $payload = [
            'environment' => app()->environment(),
            'iterations' => $iterations,
            'lines' => $lines,
            'rolledBack' => $rolledBack,
            'operations' => $transaction['operations'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Operação', 'p50 ms', 'p95 ms', 'máx. queries'], array_map(
                static fn (array $operation, string $name): array => [$name, $operation['p50Ms'], $operation['p95Ms'], $operation['maxQueries']],
                $payload['operations'],
                array_keys($payload['operations']),
            ));
            $this->line('Dados sintéticos foram revertidos pela transação externa.');
        }

        return self::SUCCESS;
    }

    /** @return array{actorId: int, locationId: int, marker: string, variantIds: list<int>} */
    private function createFixture(int $lines): array
    {
        $marker = 'inventory-benchmark-'.bin2hex(random_bytes(5)).'@example.test';
        $actorId = (int) DB::table('users')->insertGetId([
            'name' => 'Inventory benchmark',
            'email' => $marker,
            'password' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('inventory_categories')->insertGetId([
            'name' => 'Benchmark',
            'normalized_name' => 'benchmark-'.bin2hex(random_bytes(4)),
            'active' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locationId = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');
        if ($locationId === 0) {
            throw new RuntimeException('Local TI não encontrado; execute inventory:install antes do benchmark.');
        }

        $variantIds = [];
        for ($index = 1; $index <= $lines; $index++) {
            $itemId = (int) DB::table('inventory_items')->insertGetId([
                'code' => 'BEN'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'category_id' => $categoryId,
                'name' => 'Benchmark item '.$index,
                'unit' => 'UN',
                'active' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $variantId = (int) DB::table('inventory_product_variants')->insertGetId([
                'item_id' => $itemId,
                'description' => 'Benchmark variant '.$index,
                'identity_hash' => hash('sha256', 'benchmark-'.$itemId),
                'active' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $variantIds[] = $variantId;
            DB::table('inventory_balances')->insert([
                'location_id' => $locationId,
                'variant_id' => $variantId,
                'quantity' => 0,
                'version' => 1,
            ]);
        }

        return ['actorId' => $actorId, 'locationId' => $locationId, 'marker' => $marker, 'variantIds' => $variantIds];
    }

    /** @param callable(): mixed $operation @return array{p50Ms: float, p95Ms: float, maxQueries: int} */
    private function measure(int $iterations, callable $operation): array
    {
        $samples = [];
        $queryCounts = [];
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $connection = DB::connection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $startedAt = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
            $queryCounts[] = count($connection->getQueryLog());
        }

        sort($samples);

        return [
            'p50Ms' => round($samples[(int) floor((count($samples) - 1) * 0.50)], 2),
            'p95Ms' => round($samples[(int) floor((count($samples) - 1) * 0.95)], 2),
            'maxQueries' => max($queryCounts),
        ];
    }
}
