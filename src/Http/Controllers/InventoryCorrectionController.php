<?php

namespace Acme\Inventory\Http\Controllers;

use Acme\Inventory\Application\Actions\AdjustInventoryAction;
use Acme\Inventory\Application\Actions\ReverseMovementAction;
use Acme\Inventory\Domain\Exceptions\InsufficientStock;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InventoryCorrectionController
{
    public function adjust(Request $request, AdjustInventoryAction $action)
    {
        $this->authorize($request, 'inventory.adjustments.create');
        $data = $request->validate(['locationId' => ['required', 'integer', 'exists:inventory_locations,id'], 'variantId' => ['required', 'integer', 'exists:inventory_product_variants,id'], 'countedQuantity' => ['required', 'integer', 'min:0'], 'expectedBalanceVersion' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:1', 'max:5000']]);
        $reason = trim($data['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da contagem.']);
        }
        $key = $this->idempotencyKey($request);

        return response()->json(['data' => $action->execute((int) $request->user()->getAuthIdentifier(), $data['locationId'], $data['variantId'], $data['countedQuantity'], $data['expectedBalanceVersion'], $reason, $key)], 201);
    }

    public function reverse(Request $request, int $movement, ReverseMovementAction $action)
    {
        $this->authorize($request, 'inventory.movements.reverse');
        $data = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:5000']]);
        $reason = trim($data['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo do estorno.']);
        }

        try {
            $result = $action->execute($movement, (int) $request->user()->getAuthIdentifier(), $reason, $this->idempotencyKey($request));
        } catch (InsufficientStock $exception) {
            throw new HttpException(409, 'INSUFFICIENT_STOCK', $exception);
        }

        return response()->json(['data' => $result], 201);
    }

    private function authorize(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || mb_strlen($key) > 160) {
            throw ValidationException::withMessages(['idempotencyKey' => 'O header Idempotency-Key deve conter de 1 a 160 caracteres.']);
        }

        return $key;
    }
}
