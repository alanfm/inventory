<?php

namespace Acme\Inventory\Application\Actions;

use Acme\Inventory\Models\InventoryCategory;
use Acme\Inventory\Models\InventoryItem;
use Acme\Inventory\Models\InventoryProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SaveCatalogAction
{
    public function createCategory(array $data): InventoryCategory
    {
        $name = trim($data['name']);

        return InventoryCategory::query()->create(['name' => $name, 'observations' => $this->nullableTrim($data['observations'] ?? null), 'normalized_name' => mb_strtolower($name), 'active' => true, 'version' => 1]);
    }

    public function updateCategory(InventoryCategory $category, array $data): InventoryCategory
    {
        return DB::transaction(function () use ($category, $data): InventoryCategory {
            $locked = InventoryCategory::query()->lockForUpdate()->findOrFail($category->id);
            $this->checkVersion($locked->version, $data['version']);
            $name = trim($data['name']);
            $locked->fill(['name' => $name, 'observations' => $this->nullableTrim($data['observations'] ?? null), 'normalized_name' => mb_strtolower($name), 'active' => $data['active'], 'version' => $locked->version + 1])->save();

            return $locked->refresh();
        });
    }

    public function createItem(array $data, int $actorId): InventoryItem
    {
        $data['code'] = strtoupper(trim($data['code']));
        $data['name'] = trim($data['name']);
        $data['created_by'] = $actorId;
        $data['updated_by'] = $actorId;

        return InventoryItem::query()->create($data);
    }

    public function updateItem(InventoryItem $item, array $data, int $actorId): InventoryItem
    {
        return DB::transaction(function () use ($item, $data, $actorId): InventoryItem {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $this->checkVersion($locked->version, $data['version']);
            if (($locked->unit !== $data['unit'] || $locked->category_id !== (int) $data['category_id']) && DB::table('inventory_ledger_entries')->whereIn('variant_id', $locked->variants()->select('id'))->exists()) {
                throw ValidationException::withMessages(['categoryId' => 'Categoria e unidade não podem mudar após movimentação.']);
            }
            $data['code'] = strtoupper(trim($data['code']));
            $data['name'] = trim($data['name']);
            $locked->fill([...$data, 'version' => $locked->version + 1, 'updated_by' => $actorId])->save();

            return $locked->refresh();
        });
    }

    public function createVariant(array $data): InventoryProductVariant
    {
        $brand = $this->nullableTrim($data['brand'] ?? null);
        $model = $this->nullableTrim($data['model'] ?? null);
        $description = trim($data['description']);
        $identityHash = $this->identityHash($brand, $model, $description);
        if (InventoryProductVariant::query()->where('item_id', $data['item_id'])->where('identity_hash', $identityHash)->exists()) {
            throw ValidationException::withMessages(['description' => 'Esta variante já está cadastrada para o item.']);
        }

        return DB::transaction(function () use ($data, $brand, $model, $description): InventoryProductVariant {
            $variant = InventoryProductVariant::query()->create(['item_id' => $data['item_id'], 'brand' => $brand, 'model' => $model, 'description' => $description, 'identity_hash' => $this->identityHash($brand, $model, $description), 'active' => true, 'version' => 1]);
            foreach (DB::table('inventory_locations')->where('active', true)->pluck('id') as $locationId) {
                DB::table('inventory_balances')->insertOrIgnore(['location_id' => $locationId, 'variant_id' => $variant->id, 'quantity' => 0, 'version' => 1, 'updated_at' => now()]);
            }

            return $variant;
        });
    }

    public function updateVariant(InventoryProductVariant $variant, array $data): InventoryProductVariant
    {
        return DB::transaction(function () use ($variant, $data): InventoryProductVariant {
            $locked = InventoryProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $this->checkVersion($locked->version, $data['version']);
            $brand = $this->nullableTrim($data['brand'] ?? null);
            $model = $this->nullableTrim($data['model'] ?? null);
            $description = trim($data['description']);
            $identityHash = $this->identityHash($brand, $model, $description);
            if (InventoryProductVariant::query()->where('item_id', $locked->item_id)->where('identity_hash', $identityHash)->where('id', '!=', $locked->id)->exists()) {
                throw ValidationException::withMessages(['description' => 'Esta variante já está cadastrada para o item.']);
            }
            $locked->fill(['brand' => $brand, 'model' => $model, 'description' => $description, 'identity_hash' => $identityHash, 'active' => $data['active'], 'version' => $locked->version + 1])->save();

            return $locked->refresh();
        });
    }

    private function identityHash(?string $brand, ?string $model, string $description): string
    {
        $identity = array_map(static fn (?string $value): string => mb_strtolower(trim($value ?? '')), [$brand, $model, $description]);

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function checkVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new ConflictHttpException('VERSION_CONFLICT: O registro foi alterado. Atualize a página e tente novamente.');
        }
    }
}
