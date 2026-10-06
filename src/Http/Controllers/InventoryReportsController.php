<?php

namespace Acme\Inventory\Http\Controllers;

use Acme\Inventory\Application\Queries\DashboardQuery;
use Acme\Inventory\Application\Queries\InventoryReportsQuery;
use Acme\Inventory\Infrastructure\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InventoryReportsController
{
    private const TYPES = ['stock', 'replenishment', 'consumption', 'adjustments'];

    public function dashboard(Request $request, DashboardQuery $query)
    {
        $this->authorize($request, 'inventory.dashboard.view');
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $query->execute($filters['from'] ?? null, $filters['to'] ?? null)]);
    }

    public function report(Request $request, string $type, InventoryReportsQuery $query)
    {
        $this->authorize($request, 'inventory.reports.view');
        abort_unless(in_array($type, self::TYPES, true), 404);
        $filters = $this->filters($request, $type);
        $rows = $query->rows($type, $filters);
        $perPage = (int) ($filters['perPage'] ?? 20);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return response()->json(['data' => $paginator->items(), 'meta' => ['currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'perPage' => $paginator->perPage(), 'total' => $paginator->total()], 'asOf' => now()->toIso8601String()]);
    }

    public function export(Request $request, string $type, InventoryReportsQuery $query, ReportExporter $exporter)
    {
        $this->authorize($request, 'inventory.reports.view');
        $this->authorize($request, 'inventory.reports.export');
        abort_unless(in_array($type, self::TYPES, true), 404);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])]])['format'];
        $filters = $this->filters($request, $type, exporting: true);
        $generatedAt = now()->toIso8601String();
        $rows = array_map(static fn (array $row): array => [...$row, 'generatedAt' => $generatedAt, 'asOf' => $generatedAt, 'currency' => 'BRL'], $query->rows($type, $filters));
        if ($rows === []) {
            $rows = [['generatedAt' => $generatedAt, 'asOf' => $generatedAt, 'currency' => 'BRL']];
        }

        return $exporter->download($rows, $format, 'inventory-'.$type.'-'.now()->format('Ymd-His'));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, string $type, bool $exporting = false): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'itemId' => ['nullable', 'integer', 'min:1', 'exists:inventory_items,id'],
            'categoryId' => ['nullable', 'integer', 'min:1', 'exists:inventory_categories,id'],
            'groupBy' => ['nullable', Rule::in(['item', 'category', 'serviceOrderNumber', 'month'])],
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if ($type !== 'consumption' && isset($data['groupBy'])) {
            throw ValidationException::withMessages(['groupBy' => 'O agrupamento aplica-se somente ao relatório de consumo.']);
        }
        if (! isset($data['from'])) {
            $data['from'] = now(config('app.timezone'))->startOfMonth()->toDateString();
        }
        if (! isset($data['to'])) {
            $data['to'] = now(config('app.timezone'))->toDateString();
        }
        if ($exporting) {
            unset($data['page'], $data['perPage']);
        }

        return $data;
    }

    private function authorize(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }
}
