<?php

declare(strict_types=1);

namespace App\Modules\Floor\Application;

use App\Modules\Floor\Domain\Exceptions\TableInvariantException;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
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
        return DB::transaction(function () use ($main, $tables): RestaurantTable {
            // La principal no puede estar unida a otra: las uniones son planas. El invariante del modelo lo impone
            // igualmente, pero comprobarlo aquí da el mensaje correcto — «esta mesa ya está unida a otra» en lugar de
            // «no se puede encadenar».
            if ($main->isJoined()) {
                throw TableInvariantException::cannotChainJoins(
                    (string) $main->code,
                    (string) $main->joinedTo?->code,
                );
            }

            foreach ($tables as $table) {
                // Una mesa con servicio en curso no se une: unirla movería su cuenta a otra mesa sin que nadie lo
                // decidiera. Primero se cierra o se mueve su cuenta.
                if ($table->status->isBusy()) {
                    throw TableInvariantException::cannotJoinBusyTable((string) $table->code);
                }

                $table->update(['joined_to_table_id' => $main->id]);
            }

            return $main->refresh();
        });
    }

    /**
     * Deshace la unión de una mesa principal.
     *
     * Se llama al pagar —la unión es temporal (§6.4)— y también a mano cuando el grupo se va antes de consumir. Es
     * idempotente: separar una mesa que no tiene nada unido no es un error, es el estado deseado.
     */
    public function separate(RestaurantTable $main): RestaurantTable
    {
        return DB::transaction(function () use ($main): RestaurantTable {
            RestaurantTable::query()
                ->where('joined_to_table_id', $main->id)
                ->update(['joined_to_table_id' => null]);

            return $main->refresh();
        });
    }
}
