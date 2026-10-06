<?php

namespace Acme\Inventory\Http\Controllers;

use Acme\Inventory\Application\Actions\ExecuteIdempotentOperation;
use Acme\Inventory\Application\Actions\PostMovementAction;
use Acme\Inventory\Application\Actions\SaveMovementDraftAction;
use Acme\Inventory\Domain\Exceptions\InsufficientStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MovementController
{
    public function index(Request $request)
    {
        $this->authorize($request, 'inventory.movements.viewAny');
        $data = $request->validate(['status' => ['nullable', Rule::in(['DRAFT', 'POSTED', 'CANCELLED', 'REVERSED'])], 'type' => ['nullable', Rule::in(['ENTRY', 'ISSUE', 'ADJUSTMENT', 'REVERSAL'])], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = DB::table('inventory_movements')->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))->orderByDesc('id')->paginate($data['perPage'] ?? 20)->withQueryString();

        return response()->json(['data' => $rows->items(), 'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total()]]);
    }

    public function show(Request $request, int $movement)
    {
        $this->authorize($request, 'inventory.movements.view');
        $row = DB::table('inventory_movements')->where('id', $movement)->first();
        abort_if($row === null, 404);
        $row = (array) $row;
        $row['lines'] = DB::table('inventory_movement_lines')->where('movement_id', $movement)->orderBy('id')->get()->map(static function ($line): array {
            $line = (array) $line;
            $line['snapshot'] = json_decode($line['snapshot'], true, flags: JSON_THROW_ON_ERROR);

            return $line;
        });

        return response()->json(['data' => $row]);
    }

    public function create(Request $request, SaveMovementDraftAction $action, ExecuteIdempotentOperation $idempotency)
    {
        $this->authorize($request, $this->permissionForType((string) $request->input('type')));
        $data = $this->validatedDraft($request, creating: true);

        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || mb_strlen($key) > 160) {
            throw ValidationException::withMessages(['idempotencyKey' => 'O header Idempotency-Key deve conter de 1 a 160 caracteres.']);
        }
        $result = $idempotency->execute((int) $request->user()->getAuthIdentifier(), 'movement.draft.create', (string) $data['type'], $key, $data, fn (): array => $action->create((int) $request->user()->getAuthIdentifier(), $data));

        return response()->json(['data' => $result], 201);
    }

    public function update(Request $request, int $movement, SaveMovementDraftAction $action)
    {
        $row = DB::table('inventory_movements')->where('id', $movement)->first();
        abort_if($row === null, 404);
        $this->authorize($request, $this->permissionForType((string) $row->type));
        $this->authorizeDraftOwner($request, $row);
        $request->attributes->set('_movementType', $row->type);
        $data = $this->validatedDraft($request, creating: false);

        return response()->json(['data' => $action->update($movement, (int) $request->user()->getAuthIdentifier(), (int) $data['version'], $data)]);
    }

    public function cancel(Request $request, int $movement, SaveMovementDraftAction $action)
    {
        $row = DB::table('inventory_movements')->where('id', $movement)->first();
        abort_if($row === null, 404);
        $this->authorize($request, $this->permissionForType((string) $row->type));
        $user = $request->user();
        $this->authorizeDraftOwner($request, $row);

        return response()->json(['data' => $action->cancel($movement, (int) $user->getAuthIdentifier(), (bool) $user->can('inventory.movements.manageDrafts'))]);
    }

    public function post(Request $request, int $movement, PostMovementAction $action)
    {
        $row = DB::table('inventory_movements')->where('id', $movement)->first();
        abort_if($row === null, 404);
        $this->authorize($request, $this->permissionForType((string) $row->type));
        $this->authorizeDraftOwner($request, $row);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || mb_strlen($key) > 160) {
            throw ValidationException::withMessages(['idempotencyKey' => 'O header Idempotency-Key deve conter de 1 a 160 caracteres.']);
        }

        try {
            $result = $action->execute($movement, (int) $request->user()->getAuthIdentifier(), (int) $data['version'], $key);
        } catch (InsufficientStock $exception) {
            throw new HttpException(409, 'INSUFFICIENT_STOCK', $exception);
        }

        return response()->json(['data' => $result]);
    }

    /** @return array<string, mixed> */
    private function validatedDraft(Request $request, bool $creating): array
    {
        $rules = [
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(['ENTRY', 'ISSUE'])],
            'locationId' => ['sometimes', 'integer', Rule::exists('inventory_locations', 'id')->where('active', true)],
            'occurredOn' => ['nullable', 'date_format:Y-m-d'], 'origin' => ['nullable', Rule::in(['PURCHASE', 'DONATION', 'INITIAL_STOCK', 'RETURN', 'TRANSFER_RECEIPT', 'OTHER'])],
            'serviceOrderNumber' => ['nullable', 'string', 'max:120'], 'documentNumber' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'], 'observations' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.variantId' => ['required', 'integer', 'distinct:strict', 'exists:inventory_product_variants,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'], 'lines.*.unitCost' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ];
        if (! $creating) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }
        $data = $request->validate($rules);
        if (! $creating && $request->has('locationId')) {
            throw ValidationException::withMessages(['locationId' => 'O local não pode ser alterado no rascunho.']);
        }
        if (! $creating) {
            unset($data['locationId']);
        }
        $type = $data['type'] ?? $request->attributes->get('_movementType');
        if ($type === 'ISSUE') {
            foreach ($data['lines'] as &$line) {
                unset($line['unitCost']);
            }
            unset($line);
        }
        if ($creating) {
            unset($data['version']);
        }

        return $data;
    }

    private function permissionForType(string $type): string
    {
        return match ($type) {
            'ENTRY' => 'inventory.entries.create', 'ISSUE' => 'inventory.issues.create', default => 'inventory.movements.manageDrafts',
        };
    }

    private function authorize(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    private function authorizeDraftOwner(Request $request, object $movement): void
    {
        abort_unless((int) $movement->created_by === (int) $request->user()->getAuthIdentifier() || $request->user()->can('inventory.movements.manageDrafts'), 403);
    }
}
