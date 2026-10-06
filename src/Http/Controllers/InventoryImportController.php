<?php

namespace Acme\Inventory\Http\Controllers;

use Acme\Inventory\Application\Actions\ExecuteIdempotentOperation;
use Acme\Inventory\Infrastructure\TiStockWorkbookReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class InventoryImportController
{
    /** @var list<string> */
    private const LEGACY_ZERO_BALANCE_CODES = ['PEN02', 'SSD01', 'HDN02', 'TN003'];

    public function index(Request $request)
    {
        $this->authorize($request, 'inventory.imports.view');

        $batches = DB::table('inventory_import_batches')->orderByDesc('id')->paginate(20);
        $batches->through(fn (object $batch): object => $this->decodeBatch($batch));

        return response()->json(['data' => $batches]);
    }

    public function show(Request $request, int $batch)
    {
        $this->authorize($request, 'inventory.imports.view');
        $record = DB::table('inventory_import_batches')->find($batch);
        abort_if($record === null, 404);
        $rows = DB::table('inventory_import_rows')->where('batch_id', $batch)->orderBy('sheet_name')->orderBy('row_number')->paginate(100);
        $rows->through(function (object $row): object {
            foreach (['source_payload', 'corrected_payload', 'errors'] as $field) {
                $row->{$field} = $row->{$field} === null ? null : json_decode($row->{$field}, true, flags: JSON_THROW_ON_ERROR);
            }

            return $row;
        });

        return response()->json(['data' => $this->decodeBatch($record), 'rows' => [
            'data' => $rows->items(),
            'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total()],
            'links' => ['next' => $rows->nextPageUrl(), 'prev' => $rows->previousPageUrl()],
        ]]);
    }

    public function analyze(Request $request, TiStockWorkbookReader $reader)
    {
        $this->authorize($request, 'inventory.imports.execute');
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = DB::table('inventory_import_batches')->where('file_hash', $hash)->first();
        if ($existing !== null) {
            return response()->json(['data' => $existing], 200);
        }
        try {
            $type = IOFactory::identify($file->getRealPath());
            if ($type !== 'Xlsx') {
                throw ValidationException::withMessages(['file' => 'Envie uma pasta de trabalho XLSX sem macros.']);
            }
            $records = $reader->read($file->getRealPath());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Arquivo XLSX inválido ou fora dos limites aceitos.']);
        }
        if ($records === []) {
            throw ValidationException::withMessages(['file' => 'Nenhuma linha de dados foi encontrada.']);
        }
        $path = $file->storeAs('', $hash.'.xlsx', 'inventory-imports');
        $userId = (int) $request->user()->getAuthIdentifier();
        $batchId = DB::transaction(function () use ($records, $file, $hash, $path, $userId): int {
            $batchId = DB::table('inventory_import_batches')->insertGetId([
                'file_hash' => $hash, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'storage_path' => $path, 'adapter_version' => TiStockWorkbookReader::VERSION,
                'status' => 'NEEDS_REVIEW', 'analysis_version' => 1, 'created_by' => $userId,
                'counts' => json_encode(['rows' => count($records)], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($records as $record) {
                $payload = $record['values'];
                $resolution = null;
                if (($payload['date'] ?? null) === '2003-05-30' && in_array((string) ($payload['service_order_number'] ?? ''), ['1001', '1002', '1003'], true)) {
                    $payload['original_date'] = $payload['date'];
                    $payload['date'] = '2023-05-30';
                    $resolution = 'DATE_CORRECTED';
                }
                $skipReason = $this->initialSkipReason($payload);
                if ($skipReason !== null) {
                    $payload['_skipReason'] = $skipReason;
                    $resolution = 'SKIPPED';
                }
                $errors = $skipReason === null ? $this->validateCanonicalRow($payload) : [];
                DB::table('inventory_import_rows')->insert([
                    'batch_id' => $batchId, 'sheet_name' => $record['sheet'], 'row_number' => $record['row'],
                    'source_payload' => json_encode($record['values'], JSON_THROW_ON_ERROR),
                    'corrected_payload' => $resolution === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                    'resolution' => $resolution,
                    'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $batchId;
        });

        return response()->json(['data' => $this->decodeBatch(DB::table('inventory_import_batches')->find($batchId))], 201);
    }

    public function resolve(Request $request, int $batch)
    {
        $this->authorize($request, 'inventory.imports.execute');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.id' => ['required', 'integer', 'exists:inventory_import_rows,id'],
            'rows.*.action' => ['required', 'in:MAPPED,SKIPPED'],
            'rows.*.reason' => ['nullable', 'string', 'required_if:rows.*.action,SKIPPED', 'max:5000'],
            'rows.*.itemId' => ['required_if:rows.*.action,MAPPED', 'integer', 'exists:inventory_items,id'],
            'rows.*.variantId' => ['required_if:rows.*.action,MAPPED', 'integer', 'exists:inventory_product_variants,id'],
            'rows.*.type' => ['required_if:rows.*.action,MAPPED', 'in:ENTRY,ISSUE'],
        ]);
        DB::transaction(function () use ($batch, $data): void {
            $record = DB::table('inventory_import_batches')->where('id', $batch)->lockForUpdate()->first();
            abort_if($record === null, 404);
            abort_unless((int) $record->analysis_version === $data['version'] && in_array($record->status, ['NEEDS_REVIEW', 'READY'], true), 409, 'VERSION_CONFLICT');
            foreach ($data['rows'] as $resolution) {
                $row = DB::table('inventory_import_rows')->where('id', $resolution['id'])->where('batch_id', $batch)->first();
                abort_if($row === null, 422);
                $source = json_decode($row->source_payload, true, flags: JSON_THROW_ON_ERROR);
                $existingPayload = $row->corrected_payload === null ? $source : json_decode($row->corrected_payload, true, flags: JSON_THROW_ON_ERROR);
                if ($resolution['action'] === 'SKIPPED') {
                    $reason = trim((string) ($resolution['reason'] ?? ''));
                    if ($reason === '') {
                        throw ValidationException::withMessages(['rows' => 'O descarte da linha exige justificativa.']);
                    }
                    DB::table('inventory_import_rows')->where('id', $row->id)->update([
                        'corrected_payload' => json_encode([...$existingPayload, '_skipReason' => $reason], JSON_THROW_ON_ERROR),
                        'resolution' => 'SKIPPED', 'errors' => null, 'updated_at' => now(),
                    ]);

                    continue;
                }
                $candidate = $existingPayload;
                $skipReason = $this->initialSkipReason($candidate);
                if ($skipReason !== null) {
                    throw ValidationException::withMessages(['rows' => $skipReason.' A linha deve permanecer fora da carga para inserção manual.']);
                }
                $itemCode = DB::table('inventory_items')->where('id', $resolution['itemId'])->value('code');
                abort_unless(is_string($itemCode) && strtoupper(trim((string) ($candidate['code'] ?? ''))) === $itemCode, 422, 'ITEM_CODE_MISMATCH');
                abort_unless((int) DB::table('inventory_product_variants')->where('id', $resolution['variantId'])->value('item_id') === (int) $resolution['itemId'], 422, 'VARIANT_ITEM_MISMATCH');
                $candidate['itemId'] = $resolution['itemId'];
                $candidate['variantId'] = $resolution['variantId'];
                $candidate['type'] = $resolution['type'];
                $exceptions = is_array($candidate['legacyExceptions'] ?? null) ? $candidate['legacyExceptions'] : [];
                if (empty($candidate['date'])) {
                    $exceptions[] = 'Data do fato ausente na fonte';
                }
                if ($resolution['type'] === 'ENTRY' && empty($candidate['origin'])) {
                    $exceptions[] = 'Origem legada não identificada; registrada como OTHER';
                }
                if ($resolution['type'] === 'ENTRY' && empty($candidate['document_number'])) {
                    $exceptions[] = 'Documento ausente na fonte';
                }
                if ($resolution['type'] === 'ISSUE' && empty($candidate['service_order_number'])) {
                    $exceptions[] = 'Ordem de serviço ausente na fonte';
                }
                if ($resolution['type'] === 'ISSUE' && empty($candidate['observations'])) {
                    $exceptions[] = 'Observações ausentes na fonte';
                }
                if (! isset($candidate['cost']) || $candidate['cost'] === '') {
                    $exceptions[] = 'Custo histórico desconhecido';
                }
                if (isset($candidate['_source_formulas']['quantity'])) {
                    $exceptions[] = 'Quantidade obtida do valor calculado armazenado da fórmula; nenhuma fórmula foi executada';
                }
                $candidate['legacyExceptions'] = array_values(array_unique($exceptions));
                if (isset($candidate['original_date'])) {
                    $candidate['legacyExceptions'][] = 'Data legada corrigida de '.$candidate['original_date'].' para '.$candidate['date'].' pela regra da OS';
                }
                $errors = $this->validateCanonicalRow($candidate, $resolution['type']);
                DB::table('inventory_import_rows')->where('id', $row->id)->update([
                    'corrected_payload' => json_encode($candidate, JSON_THROW_ON_ERROR),
                    'resolution' => 'MAPPED', 'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            $remaining = DB::table('inventory_import_rows')->where('batch_id', $batch)->where(function ($query): void {
                $query->whereNull('corrected_payload')->orWhereNotNull('errors');
            })->exists();
            DB::table('inventory_import_batches')->where('id', $batch)->update(['status' => $remaining ? 'NEEDS_REVIEW' : 'READY', 'analysis_version' => $data['version'] + 1, 'updated_at' => now()]);
        });

        return $this->show($request, $batch);
    }

    public function commit(Request $request, int $batch)
    {
        $this->authorize($request, 'inventory.imports.execute');
        $key = (string) $request->header('Idempotency-Key');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        abort_if($key === '' || mb_strlen($key) > 160, 422, 'IDEMPOTENCY_KEY_REQUIRED');
        $actor = (int) $request->user()->getAuthIdentifier();
        $result = app(ExecuteIdempotentOperation::class)->execute(
            $actor, 'inventory.import.commit', (string) $batch, $key, ['batch' => $batch, 'version' => $data['version']],
            fn (): array => $this->commitBatch($batch, $actor, $data['version']),
        );

        return response()->json(['data' => $result]);
    }

    /** @param array<string, mixed> $source @return list<string> */
    private function validateCanonicalRow(array $source, ?string $type = null): array
    {
        $errors = [];
        $type ??= strtoupper((string) ($source['type'] ?? ''));
        if (! in_array($type, ['ENTRY', 'ISSUE'], true)) {
            $errors[] = 'type: use ENTRY ou ISSUE';
        }
        if (trim((string) ($source['code'] ?? '')) === '') {
            $errors[] = 'code: obrigatório';
        }
        if (! is_numeric($source['quantity'] ?? null) || (int) $source['quantity'] < 1 || (string) (int) $source['quantity'] !== (string) $source['quantity']) {
            $errors[] = 'quantity: deve ser inteiro positivo';
        }
        if (isset($source['date']) && $source['date'] !== '') {
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $source['date']);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $source['date']) || $parsedDate === false || $parsedDate->format('Y-m-d') !== (string) $source['date']) {
                $errors[] = 'date: use a valid AAAA-MM-DD date';
            }
        }
        if (isset($source['cost']) && $source['cost'] !== '' && ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', (string) $source['cost'])) {
            $errors[] = 'cost: use valor decimal não negativo';
        }

        return $errors;
    }

    /** @param array<string, mixed> $source */
    private function initialSkipReason(array $source): ?string
    {
        $code = strtoupper(trim((string) ($source['code'] ?? '')));
        if (in_array($code, self::LEGACY_ZERO_BALANCE_CODES, true)) {
            return 'Saldo legado do código '.$code.' foi definido como zero operacional.';
        }
        if (isset($source['_source_formulas']['quantity'])) {
            return 'Quantidade depende de fórmula/cache e será inserida manualmente.';
        }
        if ($code === '' || ! is_numeric($source['quantity'] ?? null) || (int) $source['quantity'] < 1 || (string) (int) $source['quantity'] !== (string) $source['quantity']) {
            return 'Tupla incompleta: código e quantidade inteira positiva são obrigatórios.';
        }
        if (! is_string($source['date'] ?? null) || $source['date'] === '') {
            return 'Tupla incompleta: data do fato ausente; inserir manualmente.';
        }

        return null;
    }

    private function commitBatch(int $batchId, int $actorId, int $version): array
    {
        return DB::transaction(function () use ($batchId, $actorId, $version): array {
            $batch = DB::table('inventory_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_if($batch === null, 404);
            abort_unless($batch->status === 'READY' && (int) $batch->analysis_version === $version, 409, 'IMPORT_NOT_READY');
            $lines = DB::table('inventory_import_rows')->where('batch_id', $batchId)->orderBy('id')->get();
            $locationId = DB::table('inventory_locations')->where('code', 'TI')->value('id');
            abort_if($locationId === null, 409, 'INVENTORY_LOCATION_MISSING');
            $movementIds = [];
            $skippedRowIds = [];
            foreach ($lines as $row) {
                if ($row->resolution === 'SKIPPED') {
                    $skippedRowIds[] = (int) $row->id;

                    continue;
                }
                $payload = json_decode($row->corrected_payload, true, flags: JSON_THROW_ON_ERROR);
                abort_if($row->errors !== null || ! isset($payload['variantId'], $payload['type']), 409, 'IMPORT_NOT_READY');
                abort_if($this->initialSkipReason($payload) !== null, 409, 'INCOMPLETE_IMPORT_ROW');
                $movementId = DB::table('inventory_movements')->insertGetId([
                    'type' => $payload['type'], 'status' => 'POSTED', 'location_id' => $locationId,
                    'occurred_on' => $payload['date'] ?: null, 'origin' => $payload['origin'] ?? 'OTHER',
                    'service_order_number' => $payload['service_order_number'] ?? null, 'document_number' => $payload['document_number'] ?? null,
                    'description' => $payload['description'] ?? null, 'observations' => $payload['observations'] ?? null,
                    'version' => 1, 'source' => 'IMPORT', 'import_batch_id' => $batchId, 'posted_by' => $actorId,
                    'posted_at' => now(), 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $variantId = (int) $payload['variantId'];
                $variant = DB::table('inventory_product_variants')->where('id', $variantId)->lockForUpdate()->first();
                abort_if($variant === null || (int) $variant->item_id !== (int) $payload['itemId'], 409, 'VARIANT_ITEM_MISMATCH');
                $item = DB::table('inventory_items')->where('id', $variant->item_id)->first();
                $quantity = (int) $payload['quantity'];
                $lineId = DB::table('inventory_movement_lines')->insertGetId([
                    'movement_id' => $movementId, 'variant_id' => $variantId, 'quantity' => $quantity,
                    'unit_cost' => $payload['cost'] ?? null,
                    'snapshot' => json_encode(['code' => $item->code, 'variant' => $variant->description, 'legacy' => true, 'importRowId' => $row->id, 'exceptions' => $payload['legacyExceptions'] ?? []], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('inventory_balances')->insertOrIgnore(['location_id' => $locationId, 'variant_id' => $variantId, 'quantity' => 0, 'version' => 1]);
                $balance = DB::table('inventory_balances')->where('location_id', $locationId)->where('variant_id', $variantId)->lockForUpdate()->first();
                $delta = $payload['type'] === 'ENTRY' ? $quantity : -$quantity;
                DB::table('inventory_ledger_entries')->insert(['movement_line_id' => $lineId, 'variant_id' => $variantId, 'location_id' => $locationId, 'effective_on' => $payload['date'] ?: null, 'delta' => $delta, 'posted_at' => now()]);
                DB::table('inventory_balances')->where('id', $balance->id)->update(['quantity' => (int) $balance->quantity + $delta, 'version' => (int) $balance->version + 1, 'updated_at' => now()]);
                DB::table('inventory_import_rows')->where('id', $row->id)->update(['movement_line_id' => $lineId]);
                $movementIds[] = $movementId;
            }
            DB::table('inventory_import_batches')->where('id', $batchId)->update(['status' => 'IMPORTED', 'approved_by' => $actorId, 'completed_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_audit_events')->insert([
                'entity_type' => 'import_batch', 'entity_id' => $batchId, 'action' => 'committed', 'actor_id' => $actorId,
                'before' => json_encode(['status' => 'READY']), 'after' => json_encode(['status' => 'IMPORTED', 'movementIds' => $movementIds, 'skippedRowIds' => $skippedRowIds]),
                'reason' => 'Importação legada aprovada pelo operador', 'occurred_at' => now(),
            ]);

            return ['batchId' => $batchId, 'status' => 'IMPORTED', 'movements' => $movementIds, 'skippedRows' => $skippedRowIds];
        }, 1);
    }

    private function authorize(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    private function decodeBatch(object $batch): object
    {
        $batch->counts = $batch->counts === null ? null : json_decode($batch->counts, true, flags: JSON_THROW_ON_ERROR);

        return $batch;
    }
}
