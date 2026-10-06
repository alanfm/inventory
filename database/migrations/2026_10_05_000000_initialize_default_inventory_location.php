<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_locations')->insertOrIgnore([
            'code' => 'TI', 'name' => 'Almoxarifado TI', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve the location and any movements linked to it.
    }
};
