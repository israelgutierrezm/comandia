<?php

declare(strict_types=1);

namespace App\Modules\Floor\Application;

use App\Modules\Floor\Domain\Exceptions\TableInvariantException;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Shared\Domain\Events\TablesRegrouped;
use Illuminate\Support\Facades\DB;

/**
 * Unir y separar mesas (D32, §6.4).
 *
 * ## Una unión es un estado del momento, no un documento
 *
 * Llegaron ocho personas y se juntan dos mesas de cuatro; al pagar, se separan. No hay tabla de uniones a propósito: lo
 * que alguien querría auditar es **la cuenta que la usó**, y eso ya queda registrado en la cuenta. Una tabla de uniones
 * guardaría el historial de mover sillas.
 *
 * ## Por qué esto es un servicio y no dos líneas en el controlador
 *
 * Porque la unión toca **N filas** y tiene que ser atómica: si se unen tres mesas y la tercera falla, quedarían dos
 * unidas y una suelta, con el salón mostrando una unión a medias que nadie pidió.
 *
 * ## Y avisa
 *
 * Unir y separar no cambian el estado de ninguna mesa, así que no pasan por `TableOccupancy` ni por su aviso: sin
 * `TablesRegrouped`, las demás terminales seguían ofreciendo sentar gente en la mitad de una mesa de ocho.
 */
final readonly class JoinTables
{
    /**
     * Une varias mesas a una principal.
     *
     * @param  list<RestaurantTable>  $tables las que se unen a la principal
     */
    public function join(RestaurantTable $main, array $tables): RestaurantTable
    {
        $unida = DB::transaction(function () use ($main, $tables): RestaurantTable {
            // La principal no puede estar unida a otra: las uniones son planas. El invariante del modelo lo impone
            // igualmente, pero comprobarlo aquí da el mensaje correcto — «esta mesa ya está unida a otra» en lugar de
            // «no se puede encadenar».
            if ($main->isJoined()) {
                throw TableInvariantException::cannotChainJoins(
                    (string) $main->code,
                    (string) $main->joinedTo?->code,
                );
            }

            if ($main->isArchived()) {
                throw TableInvariantException::archived((string) $main->code);
            }

            $planPrincipal = $main->zone?->floor_plan_id;

            foreach ($tables as $table) {
                // Ya colgaba de esta principal: pedirlo otra vez es el estado deseado, no un error.
                if ((int) $table->joined_to_table_id === (int) $main->id) {
                    continue;
                }

                // Mismo salón: misma sucursal y mismo plano. La sucursal sola no basta —un negocio puede tener la
                // terraza en otro plano— y la comprobación de tenant no ve nada, porque las dos mesas son del negocio.
                if ((int) $table->branch_id !== (int) $main->branch_id || $table->zone?->floor_plan_id !== $planPrincipal) {
                    throw TableInvariantException::notSameFloor((string) $table->code, (string) $main->code);
                }

                if ($table->isArchived()) {
                    throw TableInvariantException::archived((string) $table->code);
                }

                // Una mesa con servicio en curso no se une: unirla movería su cuenta a otra mesa sin que nadie lo
                // decidiera. Primero se cierra o se mueve su cuenta.
                if ($table->status->isBusy()) {
                    throw TableInvariantException::cannotJoinBusyTable((string) $table->code);
                }

                // Hacia abajo también es cadena: una mesa que ya es principal de otra unión no se cuelga de ésta.
                if ($table->joinedTables()->exists()) {
                    throw TableInvariantException::alreadyMainOfJoin((string) $table->code);
                }

                $table->update(['joined_to_table_id' => $main->id]);
            }

            return $main->refresh();
        });

        $this->avisar($unida, array_map(fn (RestaurantTable $t): string => (string) $t->ulid, $tables), joined: true);

        return $unida;
    }

    /**
     * Deshace la unión de una mesa principal.
     *
     * Se llama al pagar —la unión es temporal (§6.4)— y también a mano cuando el grupo se va antes de consumir. Es
     * idempotente: separar una mesa que no tiene nada unido no es un error, es el estado deseado (y no avisa: no pasó
     * nada).
     */
    public function separate(RestaurantTable $main): RestaurantTable
    {
        $sueltas = [];

        $separada = DB::transaction(function () use ($main, &$sueltas): RestaurantTable {
            $sueltas = RestaurantTable::query()
                ->where('joined_to_table_id', $main->id)
                ->pluck('ulid')
                ->map(fn ($ulid): string => (string) $ulid)
                ->all();

            RestaurantTable::query()
                ->where('joined_to_table_id', $main->id)
                ->update(['joined_to_table_id' => null]);

            return $main->refresh();
        });

        if ($sueltas !== []) {
            $this->avisar($separada, $sueltas, joined: false);
        }

        return $separada;
    }

    /**
     * @param  list<string>  $tableUlids
     */
    private function avisar(RestaurantTable $main, array $tableUlids, bool $joined): void
    {
        $tenantId = (int) $main->tenant_id;
        $branchUlid = (string) $main->branch->ulid;
        $mainUlid = (string) $main->ulid;

        // Tras el commit, como `TableOccupancy`: si quien llama envolvió la unión en una transacción mayor y ésta se
        // deshace, el piso no se entera de una unión que no ocurrió.
        DB::afterCommit(fn () => TablesRegrouped::dispatch($tenantId, $branchUlid, $mainUlid, $tableUlids, $joined));
    }
}
