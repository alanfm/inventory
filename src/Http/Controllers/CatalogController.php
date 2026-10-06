<?php

namespace Acme\Inventory\Http\Controllers;

use Acme\Inventory\Application\Actions\SaveCatalogAction;
use Acme\Inventory\Http\Resources\CategoryResource;
use Acme\Inventory\Http\Resources\ItemResource;
use Acme\Inventory\Http\Resources\VariantResource;
use Acme\Inventory\Models\InventoryCategory;
use Acme\Inventory\Models\InventoryItem;
use Acme\Inventory\Models\InventoryProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogController
{
    public function categories(Request $request)
    {
        $this->authorize($request, 'viewAny', InventoryCategory::class);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'active' => ['nullable', 'boolean'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = InventoryCategory::query()->when($data['search'] ?? null, fn ($query, $term) => $query->where('name', 'like', "%{$term}%"))->when(array_key_exists('active', $data), fn ($query) => $query->where('active', $data['active']))->orderBy('name')->paginate($data['perPage'] ?? 20)->withQueryString();

        return CategoryResource::collection($rows);
    }

    public function createCategory(Request $request, SaveCatalogAction $action): CategoryResource
    {
        $this->authorize($request, 'create', InventoryCategory::class);
        $request->merge(['normalized_name' => mb_strtolower(trim((string) $request->input('name')))]);
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:120'], 'observations' => ['nullable', 'string', 'max:5000'], 'normalized_name' => ['required', 'unique:inventory_categories,normalized_name']]);
        $data['name'] = trim($data['name']);

        return new CategoryResource($action->createCategory($data));
    }

    public function updateCategory(Request $request, InventoryCategory $category, SaveCatalogAction $action): CategoryResource
    {
        $this->authorize($request, 'update', $category);
        $request->merge(['normalized_name' => mb_strtolower(trim((string) $request->input('name')))]);
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:120'], 'observations' => ['nullable', 'string', 'max:5000'], 'active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'], 'normalized_name' => ['required', Rule::unique('inventory_categories')->ignore($category->id)]]);
        $data['name'] = trim($data['name']);

        return new CategoryResource($action->updateCategory($category, $data));
    }

    public function items(Request $request)
    {
        $this->authorize($request, 'viewAny', InventoryItem::class);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'categoryId' => ['nullable', 'integer', 'min:1'], 'active' => ['nullable', 'boolean'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = InventoryItem::query()->with(['category', 'variants.balances'])
            ->when($data['search'] ?? null, fn ($query, $term) => $query->where(fn ($q) => $q->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")))
            ->when($data['categoryId'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when(array_key_exists('active', $data), fn ($query) => $query->where('active', $data['active']))
            ->orderBy('code')->paginate($data['perPage'] ?? 20)->withQueryString();

        return ItemResource::collection($rows);
    }

    public function showItem(Request $request, InventoryItem $item): ItemResource
    {
        $this->authorize($request, 'view', $item);

        return new ItemResource($item->load(['category', 'variants.balances']));
    }

    public function createItem(Request $request, SaveCatalogAction $action): ItemResource
    {
        $this->authorize($request, 'create', InventoryItem::class);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate(['code' => ['required', 'string', 'min:1', 'max:32', 'regex:/^[A-Z0-9_-]+$/', 'unique:inventory_items,code'], 'categoryId' => ['required', 'integer', 'exists:inventory_categories,id'], 'name' => ['required', 'string', 'min:1', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'], 'unit' => ['required', Rule::in(['UN', 'PAR', 'CX'])]]);
        if (! InventoryCategory::query()->whereKey($data['categoryId'])->where('active', true)->exists()) {
            throw ValidationException::withMessages(['categoryId' => 'Selecione uma categoria ativa.']);
        }
        $item = $action->createItem(['category_id' => $data['categoryId'], 'code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'unit' => $data['unit']], (int) $request->user()->getAuthIdentifier());

        return new ItemResource($item->load('category'));
    }

    public function updateItem(Request $request, InventoryItem $item, SaveCatalogAction $action): ItemResource
    {
        $this->authorize($request, 'update', $item);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate(['code' => ['required', 'string', 'min:1', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('inventory_items')->ignore($item->id)], 'categoryId' => ['required', 'integer', 'exists:inventory_categories,id'], 'name' => ['required', 'string', 'min:1', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'], 'unit' => ['required', Rule::in(['UN', 'PAR', 'CX'])], 'active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        if (! InventoryCategory::query()->whereKey($data['categoryId'])->where('active', true)->exists()) {
            throw ValidationException::withMessages(['categoryId' => 'Selecione uma categoria ativa.']);
        }
        $updated = $action->updateItem($item, ['category_id' => $data['categoryId'], 'code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'unit' => $data['unit'], 'active' => $data['active'], 'version' => $data['version']], (int) $request->user()->getAuthIdentifier());

        return new ItemResource($updated->load('category'));
    }

    public function createVariant(Request $request, InventoryItem $item, SaveCatalogAction $action): VariantResource
    {
        $this->authorize($request, 'create', InventoryProductVariant::class);
        $data = $request->validate(['brand' => ['nullable', 'string', 'max:120'], 'model' => ['nullable', 'string', 'max:120'], 'description' => ['required', 'string', 'min:1', 'max:2000']]);
        if (! $item->active) {
            throw ValidationException::withMessages(['itemId' => 'Reative o item antes de adicionar variantes.']);
        }

        return new VariantResource($action->createVariant(['item_id' => $item->id, ...$data]));
    }

    public function updateVariant(Request $request, InventoryProductVariant $variant, SaveCatalogAction $action): VariantResource
    {
        $this->authorize($request, 'update', $variant);
        $data = $request->validate(['brand' => ['nullable', 'string', 'max:120'], 'model' => ['nullable', 'string', 'max:120'], 'description' => ['required', 'string', 'min:1', 'max:2000'], 'active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);

        return new VariantResource($action->updateVariant($variant, $data));
    }

    private function authorize(Request $request, string $ability, string|object $subject): void
    {
        abort_unless($request->user()?->can($ability, $subject), 403);
    }
}
