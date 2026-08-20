<?php

declare(strict_types=1);

namespace App\Modules\Floor\Application;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Domain\Exceptions\TableInvariantException;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;

/**
 * El estado de ocupación de una mesa: la única puerta por la que se mueve.
 *
 * ## Por qué existe, y por qué la escribí tarde
 *
 * El paso 7 abría la cuenta y hacía `$table->update(['status' => Occupied])` desde `Pos`, con un comentario que decía
 * «`Pos` es el dueño de ese hecho». Era una racionalización, y el candado de fronteras (`ModuleBoundariesTest`) la
 * destapó al pedir que la dependencia `Pos → Floor` se declarara.
 *
 * Si `Pos` fuera el dueño del estado de una mesa, la columna viviría en `Pos`. No vive ahí: la pantalla de piso la lee,
 * `JoinTables` manipula mesas, y las invariantes de una mesa —unida, reservada, por limpiar— son de este módulo. Un
 * `update()` desde fuera las salta todas, y peor: `Pos` había acabado leyendo el ajuste `floor.use_cleaning_state` y
 * deshaciendo las uniones temporales de `joined_to_table_id`. Eso ya es un módulo implementando las reglas de otro. El
 * día que `Floor` agregue una regla —reservaciones (D33), o un grupo unido que no se libera por partes— `Pos` no se
 * enteraría.
 *
 * ## Por qué NO es un evento, que era la respuesta tentadora
 *
 * La regla 3 de §2 manda los efectos entre módulos por evento de dominio, y aquí sería un error. La ocupación tiene que
 * ser **inmediata y en la misma transacción**: la comprobación de «no se abre una segunda cuenta en una mesa ocupada»
 * lee justo este estado, así que con consistencia eventual dos meseros sientan a dos grupos en la mesa 4 y el sistema
 * los deja.
 *
 * Los eventos son para los efectos que **pueden** llegar tarde —el descuento de inventario, el asiento del diario— y
 * ése es el criterio, no la frontera por sí misma. Lo que sí exige la regla es que el acoplamiento sea explícito y
 * estrecho: `Pos` depende de este SERVICIO, que es superficie pública del módulo (§2), y no del modelo Eloquent.
 *
 * ## Qué se queda en `Pos`
 *
 * La pregunta «¿queda alguna cuenta viva en esta mesa?», porque es una pregunta sobre cuentas. `Pos` la contesta y pide
 * la liberación; qué significa liberar —libre o por limpiar, y qué pasa con las uniones— lo decide este servicio.
 */
final readonly class TableOccupancy
{
    public function __construct(private Settings $settings) {}

    /**
     * Ocupa la mesa al sentar gente.
     *
     * Vuelve a comprobar la disponibilidad aunque quien llama ya la haya comprobado. No es desconfianza: entre la
     * comprobación de quien abre y esta escritura hay una transacción de por medio, y esta clase es la que responde por
     * la invariante.
     */
    public function occupy(RestaurantTable $table): void
    {
        if (! $table->isAvailable()) {
            throw TableInvariantException::notAvailable((string) $table->code);
        }

        $table->update(['status' => TableStatus::Occupied]);
    }

    /**
     * La mesa pasa a «cuenta solicitada».
     *
     * §6.4 pinta este estado en la vista de piso y hasta el paso 7 **nada lo escribía**: el enum lo tenía, la pantalla
     * lo sabía dibujar, y ninguna transición llegaba a él. Es la señal de que a esa mesa le falta cobrar y no volver a
     * atenderla, y sin ella el encargado de piso no distingue una mesa que come de una que espera el cobro.
     *
     * No falla si la mesa no está ocupada: una cuenta de barra movida a mesa, o una mesa liberada a mano por error,
     * dejarían la petición de cuenta —que es lo que de verdad importa— colgada de un estado del salón.
     */
    public function markBillRequested(RestaurantTable $table): void
    {
        if (! $table->status->isBusy()) {
            return;
        }

        $table->update(['status' => TableStatus::BillRequested]);
    }

    /**
     * Libera la mesa: el servicio terminó.
     *
     * El estado al que vuelve es configurable (§6.4): con `floor.use_cleaning_state` encendido pasa a «por limpiar»,
     * que es la señal que un encargado de piso necesita; apagado vuelve directo a libre, porque en una fonda el mesero
     * limpia y sienta a los siguientes en el mismo movimiento.
     *
     * Y deshace la unión temporal, que §6.4 llama «operativa y temporal»: su final es el pago. Vive aquí y no en `Pos`
     * porque `joined_to_table_id` es una columna de este módulo y su regla es de este módulo.
     */
    public function release(RestaurantTable $table, int $branchId): void
    {
        $usarLimpieza = (bool) $this->settings->forBranch('floor.use_cleaning_state', $branchId);

        $table->update([
            'status' => $usarLimpieza ? TableStatus::NeedsCleaning : TableStatus::Free,
        ]);

        RestaurantTable::query()
            ->where('joined_to_table_id', $table->id)
            ->update(['joined_to_table_id' => null]);
    }
}
