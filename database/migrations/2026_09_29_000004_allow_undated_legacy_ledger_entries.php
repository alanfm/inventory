<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_ledger_entries', function (Blueprint $table): void {
            $table->date('effective_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_ledger_entries')->whereNull('effective_on')->exists()) {
            throw new RuntimeException('Cannot make effective_on required while legacy undated entries exist.');
        }
        Schema::table('inventory_ledger_entries', function (Blueprint $table): void {
            $table->date('effective_on')->nullable(false)->change();
        });
    }
};
