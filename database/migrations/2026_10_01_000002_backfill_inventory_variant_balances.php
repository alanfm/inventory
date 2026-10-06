<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $locationId = DB::table('inventory_locations')->where('code', 'TI')->value('id');

        if ($locationId === null) {
            return;
        }

        DB::table('inventory_product_variants')->orderBy('id')->pluck('id')->each(
            fn (int $variantId) => DB::table('inventory_balances')->insertOrIgnore([
                'location_id' => $locationId,
                'variant_id' => $variantId,
                'quantity' => 0,
                'version' => 1,
                'updated_at' => now(),
            ]),
        );
    }

    public function down(): void
    {
        // Balances are operational projections and must not be removed on rollback.
    }
};
