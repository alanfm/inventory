<?php

namespace Acme\Inventory\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveMovementDraftAction
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(int $actorId, array $data): array
    {
        return DB::transaction(function () use ($actorId, $data): array {
            $data['locationId'] ??= app(ResolveDefaultLocationAction::class)->execute();
            $movementId = DB::table('inventory_movements')->insertGetId([
                'type' => $data['type'], 'status' => 'DRAFT', 'location_id' => $data['locationId'],
                'occurred_on' => $data['occurredOn'] ?? null, 'origin' => $data['origin'] ?? null,
                'service_order_number' => $data['serviceOrderNumber'] ?? null, 'document_number' => $data['documentNumber'] ?? null,
                'description' => $data['description'] ?? null, 'observations' => $data['observations'] ?? null,
                'version' => 1, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->replaceLines($movementId, $data['lines']);
            $this->audit($movementId, $actorId, 'draft_created', null, $data);

            return $this->get($movementId);
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(int $movementId, int $actorId, int $version, array $data): array
    {
        return DB::transaction(function () use ($movementId, $actorId, $version, $data): array {
            $movement = DB::table('inventory_movements')->where('id', $movementId)->lockForUpdate()->first();
            abort_if($movement === null, 404);
            abort_unless($movement->status === 'DRAFT' && (int) $movement->created_by === $actorId && (int) $movement->version === $version, 409, 'VERSION_CONFLICT');
            DB::table('inventory_movements')->where('id', $movementId)->update([
                'occurred_on' => $data['occurredOn'] ?? null, 'origin' => $data['origin'] ?? null,
                'service_order_number' => $data['serviceOrderNumber'] ?? null, 'document_number' => $data['documentNumber'] ?? null,
                'description' => $data['description'] ?? null, 'observations' => $data['observations'] ?? null,
                'version' => $version + 1, 'updated_at' => now(),
            ]);
            $this->replaceLines($movementId, $data['lines']);
            $this->audit($movementId, $actorId, 'draft_updated', ['version' => $version], $data);

            return $this->get($movementId);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(int $movementId, int $actorId, bool $manageOthers): array
    {
        return DB::transaction(function () use ($movementId, $actorId, $manageOthers): array {
            $movement = DB::table('inventory_movements')->where('id', $movementId)->lockForUpdate()->first();
            abort_if($movement === null, 404);
            abort_unless($movement->status === 'DRAFT' && ((int) $movement->created_by === $actorId || $manageOthers), 403);
            DB::table('inventory_movements')->where('id', $movementId)->update(['status' => 'CANCELLED', 'version' => $movement->version + 1, 'updated_at' => now()]);
            $this->audit($movementId, $actorId, 'draft_cancelled', ['status' => 'DRAFT'], ['status' => 'CANCELLED']);

            return $this->get($movementId);
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(int $movementId, array $lines): void
    {
        DB::table('inventory_movement_lines')->where('movement_id', $movementId)->delete();
        foreach ($lines as $line) {
            $variant = DB::table('inventory_product_variants as v')->join('inventory_items as i', 'i.id', '=', 'v.item_id')->where('v.id', $line['variantId'])->where('v.active', true)->where('i.active', true)->first(['v.id', 'v.brand', 'v.model', 'v.description', 'i.code', 'i.name', 'i.unit']);
            if ($variant === null) {
                throw ValidationException::withMessages(['lines' => 'Uma variante não existe ou está inativa.']);
            }
            DB::table('inventory_movement_lines')->insert([
                'movement_id' => $movementId, 'variant_id' => $variant->id, 'quantity' => $line['quantity'],
                'unit_cost' => $line['unitCost'] ?? null,
                'snapshot' => json_encode(['code' => $variant->code, 'itemName' => $variant->name, 'unit' => $variant->unit, 'brand' => $variant->brand, 'model' => $variant->model, 'description' => $variant->description], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function get(int $movementId): array
    {
        $movement = (array) DB::table('inventory_movements')->where('id', $movementId)->first();
        $movement['lines'] = DB::table('inventory_movement_lines')->where('movement_id', $movementId)->orderBy('id')->get()->map(static function ($line): array {
            $line = (array) $line;
            $line['snapshot'] = json_decode($line['snapshot'], true, flags: JSON_THROW_ON_ERROR);

            return $line;
        })->all();

        return $movement;
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(int $movementId, int $actorId, string $action, ?array $before, array $after): void
    {
        DB::table('inventory_audit_events')->insert(['entity_type' => 'movement', 'entity_id' => $movementId, 'action' => $action, 'actor_id' => $actorId, 'before' => $before === null ? null : json_encode($before), 'after' => json_encode($after), 'occurred_at' => now()]);
    }
}
