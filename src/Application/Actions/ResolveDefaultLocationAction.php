<?php

namespace Acme\Inventory\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveDefaultLocationAction
{
    public function execute(): int
    {
        DB::table('inventory_locations')->insertOrIgnore([
            'code' => 'TI', 'name' => 'Almoxarifado TI', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $location = DB::table('inventory_locations')->where('code', 'TI')->first();
        if ($location === null || ! $location->active) {
            throw ValidationException::withMessages(['locationId' => 'O local padrão do almoxarifado está inativo. Contate o administrador.']);
        }

        return (int) $location->id;
    }
}
