<?php

namespace Acme\Inventory\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileInventoryCommand extends Command
{
    protected $signature = 'inventory:reconcile {--json : Emit machine-readable output}';

    protected $description = 'Compare inventory balance projections with the append-only ledger.';

    public function handle(): int
    {
        $rows = DB::table('inventory_balances as b')->leftJoin('inventory_ledger_entries as l', function ($join): void {
            $join->on('l.variant_id', '=', 'b.variant_id')->on('l.location_id', '=', 'b.location_id');
        })->groupBy('b.variant_id', 'b.location_id', 'b.quantity')->havingRaw('b.quantity <> COALESCE(SUM(l.delta), 0)')
            ->get(['b.variant_id', 'b.location_id', 'b.quantity as projected', DB::raw('COALESCE(SUM(l.delta), 0) as ledger')])
            ->map(static fn ($row): array => ['variantId' => (int) $row->variant_id, 'locationId' => (int) $row->location_id, 'projected' => (int) $row->projected, 'ledger' => (int) $row->ledger])
            ->all();

        if ($this->option('json')) {
            $this->line(json_encode(['consistent' => $rows === [], 'discrepancies' => $rows], JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Variant', 'Location', 'Projection', 'Ledger'], $rows);
            $this->info($rows === [] ? 'Inventory balances reconcile.' : count($rows).' discrepancy(s) found.');
        }

        return $rows === [] ? self::SUCCESS : self::FAILURE;
    }
}
