<?php

declare(strict_types=1);

namespace App\Modules\Printing\Application;

use App\Modules\Printing\Domain\Enums\PrintJobStatus;
use App\Modules\Printing\Domain\Exceptions\PrintJobException;
use App\Modules\Printing\Infrastructure\Models\PrintAgent;
use App\Modules\Printing\Infrastructure\Models\PrintJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El contrato del agente: reclamar, reportar, reintentar.
 *
 * ## Reclamar es EXCLUSIVO, y por eso hay un lock
 *
 * Puede haber varios agentes en la misma sucursal —una tableta con el puente de Flutter y una computadora en la caja— y
 * sin exclusión los dos leerían los mismos pendientes y la cocina recibiría cada comanda dos veces. El `lockForUpdate()`
 * dentro de la transacción es lo que hace que el segundo agente espere y encuentre el lote ya tomado.
 *
 * Es el mismo mecanismo del asignador de folios, y por la misma razón: dos lectores concurrentes de «lo siguiente» sin
 * lock leen lo mismo.
 *
 * ## En LOTE y no de uno en uno
 *
 * Una mesa de ocho produce tres comandas a la vez. Pedirlas una por una serían tres viajes de red desde la cocina y tres
 * locks, con el papel saliendo a destiempo. El tope existe para que un agente que despierta después de una caída no se
 * lleve doscientos trabajos y bloquee la tabla mientras los imprime.
 *
 * ## Reportar es IDEMPOTENTE
 *
 * El agente vive en una computadora de cocina con una red que se cae: reporta «impreso», no recibe respuesta y vuelve a
 * reportar. La segunda vez no falla ni cuenta otro intento — devuelve el mismo trabajo ya impreso. Sin esto, el agente
 * tendría que llevar su propio registro de qué reportó, que es pedirle a la parte menos confiable del sistema que
 * recuerde cosas.
 */
final readonly class PrintJobQueue
{
    /**
     * Cuántos trabajos se lleva un agente de una vez.
     *
     * Diez cubre la mesa de ocho con margen. Es un tope y no una promesa: si hay tres pendientes, se lleva tres.
     */
    private const LOTE = 10;

    /**
     * Reclama los pendientes de la sucursal del agente.
     *
     * @return Collection<int, PrintJob>
     */
    public function claim(PrintAgent $agent, ?int $limit = null): Collection
    {
        return DB::transaction(function () use ($agent, $limit): Collection {
            $pendientes = PrintJob::query()
                ->where('branch_id', $agent->branch_id)
                ->pending()

                // Por `id` y no por `created_at`: dos trabajos creados en el mismo segundo —la mesa de ocho— tendrían la
                // misma marca de tiempo y el orden quedaría a merced de MySQL. El papel saldría desordenado.
                ->orderBy('id')
                ->limit($limit === null ? self::LOTE : max(1, min($limit, self::LOTE)))
                ->lockForUpdate()
                ->get();

            if ($pendientes->isEmpty()) {
                $this->touchLastSeen($agent);

                return $pendientes;
            }

            $ahora = CarbonImmutable::now();

            PrintJob::query()
                ->whereIn('id', $pendientes->pluck('id'))
                ->update([
                    'status' => PrintJobStatus::Claimed->value,
                    'claimed_by_agent' => $agent->name,
                    'claimed_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

            $this->touchLastSeen($agent);

            return PrintJob::query()->whereIn('id', $pendientes->pluck('id'))->orderBy('id')->get();
        });
    }

    /**
     * El agente reporta que salió el papel.
     */
    public function markPrinted(PrintJob $job, PrintAgent $agent): PrintJob
    {
        $this->touchLastSeen($agent);

        // Idempotente: el mismo reporte dos veces devuelve lo mismo sin contar otro intento.
        if ($job->status === PrintJobStatus::Printed) {
            return $job;
        }

        $this->assertClaimedBy($job, $agent);

        $job->update([
            'status' => PrintJobStatus::Printed,
            'printed_at' => CarbonImmutable::now(),
            'attempts' => $job->attempts + 1,
            'last_error' => null,
        ]);

        return $job->refresh();
    }

    /**
     * El agente reporta que no pudo.
     *
     * ## No se reintenta solo, y es una decisión
     *
     * Un fallo de impresión casi nunca es transitorio: se acabó el papel, la impresora está apagada, la IP cambió.
     * Reintentar solo produciría veinte intentos en un minuto y, cuando alguien por fin pusiera papel, veinte comandas
     * saliendo juntas — con platos repetidos que la cocina no puede distinguir.
     *
     * Así que el trabajo queda en `failed` con su motivo, visible en la pantalla, y una persona decide reintentarlo.
     * Es el mismo criterio que el POS aplica al inventario: preferir que un humano vea el problema antes que un
     * automatismo lo multiplique.
     */
    public function markFailed(PrintJob $job, PrintAgent $agent, string $error): PrintJob
    {
        $this->touchLastSeen($agent);

        if ($job->status === PrintJobStatus::Failed) {
            return $job;
        }

        $this->assertClaimedBy($job, $agent);

        $job->update([
            'status' => PrintJobStatus::Failed,
            'failed_at' => CarbonImmutable::now(),
            'attempts' => $job->attempts + 1,
            'last_error' => mb_substr($error, 0, 300),
        ]);

        return $job->refresh();
    }

    /**
     * Vuelve a poner un trabajo en la cola.
     *
     * Sirve para las dos cosas que pasan de verdad: reintentar uno que falló —ya hay papel en la impresora— y liberar
     * uno que un agente reclamó antes de morirse. Las dos acaban igual, en `pending`, porque desde la cola son el mismo
     * hecho: nadie lo está imprimiendo y hay que volver a repartirlo.
     *
     * `attempts` NO se reinicia: es la cuenta de cuántas veces se ha intentado sacar este papel, y reiniciarla borraría
     * justo la señal de que algo lleva rato sin salir.
     */
    public function requeue(PrintJob $job): PrintJob
    {
        if (! $job->status->canRequeue()) {
            throw PrintJobException::transitionNotAllowed($job->status->label(), 'pendiente');
        }

        $job->update([
            'status' => PrintJobStatus::Pending,
            'claimed_by_agent' => null,
            'claimed_at' => null,
            'failed_at' => null,
        ]);

        return $job->refresh();
    }

    private function assertClaimedBy(PrintJob $job, PrintAgent $agent): void
    {
        if ($job->claimed_by_agent !== $agent->name) {
            throw PrintJobException::notClaimedByAgent((string) $agent->name);
        }
    }

    /**
     * «Visto hace 3 segundos».
     *
     * Se toca en cada operación del agente, incluso cuando no había nada que reclamar — sobre todo entonces: un agente
     * sano sin trabajos y un agente muerto se ven idénticos desde la cola, y la diferencia es exactamente esta marca.
     */
    private function touchLastSeen(PrintAgent $agent): void
    {
        PrintAgent::query()->whereKey($agent->id)->update(['last_seen_at' => CarbonImmutable::now()]);
    }
}
