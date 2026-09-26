<?php

declare(strict_types=1);

namespace App\Modules\Shared\Listeners;

use App\Modules\Shared\Domain\Events\Broadcast\FloorChanged;
use App\Modules\Shared\Domain\Events\TablesRegrouped;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Avisa al piso en vivo que unas mesas se unieron o se separaron.
 *
 * Gemelo de {@see BroadcastFloorChanges}: traduce el hecho del dominio al MISMO aviso `floor.changed`, porque la
 * pantalla se pinta con una sola petición y lo que necesita del canal es saber que algo cambió, no el detalle. El
 * motivo distinto (`tables_regrouped`) deja que una pantalla futura decida si le interesa.
 */
final readonly class BroadcastTablesRegrouped
{
    public function handle(TablesRegrouped $event): void
    {
        $tenantUlid = Tenant::query()->whereKey($event->tenantId())->value('ulid');

        if ($tenantUlid === null) {
            return;
        }

        FloorChanged::dispatch(
            (string) $tenantUlid,
            $event->branchUlid,
            $event->mainTableUlid,
            null,
            null,
            'tables_regrouped',
        );
    }
}
