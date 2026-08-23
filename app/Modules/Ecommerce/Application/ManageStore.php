<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Organization\Infrastructure\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Configura la tienda del negocio (Iteración 8, Tanda B): sus datos públicos y **qué sucursales atiende** (subconjunto
 * configurable). Una tienda por tenant.
 */
final class ManageStore
{
    public function current(): ?Store
    {
        return Store::query()->with('storeBranches')->first();
    }

    /**
     * @param  array{slug: string, name: string, is_active: bool, theme_primary: string}  $data
     * @param  list<string>  $branchUlids  las sucursales que la tienda atiende
     */
    public function save(array $data, array $branchUlids): Store
    {
        return DB::transaction(function () use ($data, $branchUlids): Store {
            $store = Store::query()->first() ?? new Store();
            $store->fill($data);
            $store->save();

            // Sólo sucursales del propio negocio (el global scope de Branch ya lo garantiza).
            $branchIds = Branch::query()->whereIn('ulid', $branchUlids)->pluck('id');

            $store->storeBranches()->delete();

            foreach ($branchIds as $branchId) {
                $store->storeBranches()->create(['branch_id' => $branchId]);
            }

            return $store->refresh();
        });
    }
}
