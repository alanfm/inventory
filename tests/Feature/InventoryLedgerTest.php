<?php

namespace Acme\Inventory\Tests\Feature;

use Acme\Inventory\Application\Actions\ExecuteIdempotentOperation;
use Acme\Inventory\Application\Actions\PostMovementAction;
use Acme\Inventory\Domain\Exceptions\IdempotencyConflict;
use Acme\Inventory\Domain\Exceptions\InsufficientStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_is_atomic_idempotent_and_reconciles(): void
    {
        $this->artisan('inventory:install')->assertSuccessful();
        [$userId, $locationId, $variantId] = $this->fixture();
        $entry = $this->movement($userId, $locationId, $variantId, 'ENTRY', 7);
        $action = app(PostMovementAction::class);
        $first = $action->execute($entry, $userId, 1, 'entry-once');
        self::assertSame($first, $action->execute($entry, $userId, 1, 'entry-once'));
        self::assertSame(7, (int) DB::table('inventory_balances')->where('variant_id', $variantId)->value('quantity'));

        $issue = $this->movement($userId, $locationId, $variantId, 'ISSUE', 8);
        try {
            $action->execute($issue, $userId, 1, 'issue-once');
            self::fail('Expected insufficient stock.');
        } catch (InsufficientStock) {
            self::assertSame('DRAFT', DB::table('inventory_movements')->where('id', $issue)->value('status'));
        }

        try {
            app(ExecuteIdempotentOperation::class)->execute($userId, 'test', '1', 'same-key', ['a' => 1], fn () => ['ok' => true]);
            app(ExecuteIdempotentOperation::class)->execute($userId, 'test', '1', 'same-key', ['a' => 2], fn () => ['ok' => true]);
            self::fail('Expected idempotency conflict.');
        } catch (IdempotencyConflict) {
            self::assertDatabaseCount('inventory_idempotency_keys', 2);
        }

        $this->artisan('inventory:reconcile', ['--json' => true])->expectsOutputToContain('"consistent":true')->assertSuccessful();
    }

    /** @return array{int, int, int} */
    private function fixture(): array
    {
        $user = User::query()->create(['name' => 'Ledger tester', 'email' => 'ledger-'.bin2hex(random_bytes(4)).'@example.test']);
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Teste', 'normalized_name' => 'teste', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('inventory_items')->insertGetId(['code' => 'LED-'.strtoupper(bin2hex(random_bytes(2))), 'category_id' => $category, 'name' => 'Item teste', 'unit' => 'UN', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $variant = DB::table('inventory_product_variants')->insertGetId(['item_id' => $item, 'description' => 'Variante teste', 'identity_hash' => hash('sha256', (string) $item), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $location = (int) DB::table('inventory_locations')->where('code', 'TI')->value('id');

        return [$user->id, $location, $variant];
    }

    private function movement(int $userId, int $locationId, int $variantId, string $type, int $quantity): int
    {
        $movement = DB::table('inventory_movements')->insertGetId(['type' => $type, 'status' => 'DRAFT', 'location_id' => $locationId, 'occurred_on' => now()->toDateString(), 'origin' => $type === 'ENTRY' ? 'PURCHASE' : null, 'document_number' => $type === 'ENTRY' ? 'TEST-1' : null, 'description' => $type === 'ISSUE' ? 'Teste' : null, 'service_order_number' => $type === 'ISSUE' ? 'OS-1' : null, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_movement_lines')->insert(['movement_id' => $movement, 'variant_id' => $variantId, 'quantity' => $quantity, 'snapshot' => json_encode(['description' => 'Variante teste'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $movement;
    }
}
