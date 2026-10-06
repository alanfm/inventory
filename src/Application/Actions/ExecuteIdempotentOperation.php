<?php

namespace Acme\Inventory\Application\Actions;

use Acme\Inventory\Domain\Exceptions\IdempotencyConflict;
use Illuminate\Support\Facades\DB;

final class ExecuteIdempotentOperation
{
    /** @param array<string, mixed> $payload @param callable(): array<string, mixed> $callback @return array<string, mixed> */
    public function execute(int $actorId, string $operation, string $resourceKey, string $key, array $payload, callable $callback): mixed
    {
        if ($key === '' || mb_strlen($key) > 160) {
            throw new \InvalidArgumentException('Idempotency-Key must contain 1 to 160 characters.');
        }

        return DB::transaction(function () use ($actorId, $operation, $resourceKey, $key, $payload, $callback): mixed {
            $canonical = $this->canonicalize($payload);
            $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
            $scope = ['actor_id' => $actorId, 'operation' => $operation, 'resource_key' => $resourceKey, 'key' => $key];
            $inserted = DB::table('inventory_idempotency_keys')->insertOrIgnore([...$scope, 'request_hash' => $hash, 'result' => null, 'created_at' => now(), 'updated_at' => now()]) === 1;
            $existing = DB::table('inventory_idempotency_keys')->where($scope)->lockForUpdate()->first();

            if (! $inserted && $existing !== null) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new IdempotencyConflict('The idempotency key was already used with a different request.');
                }

                if ($existing->result === null) {
                    throw new \LogicException('An idempotency operation has no durable result.');
                }

                return json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR);
            }

            $result = $callback();
            DB::table('inventory_idempotency_keys')->where($scope)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = array_is_list($entry) ? array_map(fn ($item) => is_array($item) ? $this->canonicalize($item) : $item, $entry) : $this->canonicalize($entry);
            }
        }
        unset($entry);

        return $value;
    }
}
