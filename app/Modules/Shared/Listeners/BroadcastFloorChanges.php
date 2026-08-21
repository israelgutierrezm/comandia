<?php

declare(strict_types=1);

namespace App\Modules\Shared\Listeners;

use App\Modules\Shared\Domain\Events\Broadcast\FloorChanged;
use App\Modules\Shared\Domain\Events\TableStateChanged;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Traduce un hecho del dominio en un aviso para el piso en vivo.
 *
 * ## Por qué hay una traducción y no se difunde el evento de dominio
 *
 * Porque son dos contratos con dos públicos. `TableStateChanged` lo consume el servidor y puede crecer con lo que
 * necesite un oyente futuro; `FloorChanged` sale por un canal que oye **todo el que atiende**. Sin esta capa, añadir
 * un campo al evento de dominio lo publicaría — y nadie se enteraría hasta que el campo fuera algo que no debía salir.
 *
 * Aquí la traducción es casi una copia, y eso es deliberado: la regla vale precisamente porque no depende de que el
 * evento de hoy sea inofensivo.
 *
 * ## El ULID del tenant, y por qué no viaja en el evento de dominio
 *
 * Los eventos del kernel llevan la llave **interna** del negocio (D231): no salen del servidor, y el contexto de
 * tenancy se abre con el entero. Un canal sí sale, así que necesita el ULID — exponer el id secuencial en el nombre de
 * un canal diría a cualquier cliente cuántos negocios hay y en qué orden se dieron de alta.
 */
final readonly class BroadcastFloorChanges
{
    public function handle(TableStateChanged $event): void
    {
        $tenantUlid = Tenant::query()->whereKey($event->tenantId())->value('ulid');

        if ($tenantUlid === null) {
            // El negocio se borró entre el hecho y el aviso. No hay a quién avisarle, y reventar aquí dejaría el job
            // reintentando para siempre por algo que ya no existe.
            return;
        }

        FloorChanged::dispatch(
            (string) $tenantUlid,
            $event->branchUlid,
            $event->tableUlid,
            $event->to,
            $event->accountUlid,
            'table_state',
        );
    }
}
