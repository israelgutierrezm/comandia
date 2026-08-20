<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Controllers;

use App\Modules\Printing\Application\PrintJobQueue;
use App\Modules\Printing\Http\Middleware\AuthenticatePrintAgent;
use App\Modules\Printing\Http\Resources\PrintJobResource;
use App\Modules\Printing\Infrastructure\Models\PrintAgent;
use App\Modules\Printing\Infrastructure\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * El contrato del agente de impresión (§9.4).
 *
 * ## Esto es lo que NO se puede improvisar después
 *
 * El ejecutable del agente no entra en esta iteración —ni instalador, ni descubrimiento de impresoras, ni servicio de
 * Windows— pero el contrato sí, porque es lo que las dos implementaciones previstas (el puente Flutter de v1 y el agente
 * de escritorio) tienen que compartir. Cambiarlo después significa actualizar software instalado en computadoras de
 * cocina, que es exactamente el cambio que nadie quiere hacer.
 *
 * Por eso el paso incluye un cliente de prueba (`comandia:print:agent`) que lo consume de punta a punta e imprime a
 * archivo: sin él el contrato estaría escrito pero no verificado.
 *
 * ## Rutas separadas de las de la interfaz
 *
 * Estas tres viven bajo `print-agent/` y las autentica el token del agente. Las de la pantalla de administración
 * —listar trabajos, reintentar— viven aparte y las autentica una sesión con permisos. Mezclarlas obligaría a un solo
 * middleware a decidir cuál de las dos identidades aplica, que es como se cuelan los agujeros.
 */
final class PrintAgentJobController
{
    public function __construct(private readonly PrintJobQueue $queue) {}

    /**
     * Reclama lo pendiente de la sucursal del agente.
     *
     * Devuelve `200` con una lista, vacía cuando no hay nada — que es la respuesta más común y no es un error. Un 204 o
     * un 404 obligarían al agente a distinguir dos casos que se manejan igual: volver a preguntar en unos segundos.
     *
     * @return AnonymousResourceCollection<\Illuminate\Support\Collection<int, PrintJob>>
     */
    public function next(Request $request): AnonymousResourceCollection
    {
        $agent = $this->agent($request);

        $trabajos = $this->queue->claim(
            $agent,
            $request->filled('limit') ? $request->integer('limit') : null,
        );

        return PrintJobResource::collection($trabajos->load('printer'));
    }

    /**
     * El agente reporta que salió el papel.
     */
    public function printed(Request $request, PrintJob $printJob): PrintJobResource
    {
        return new PrintJobResource(
            $this->queue->markPrinted($printJob, $this->agent($request))->load('printer'),
        );
    }

    /**
     * El agente reporta que no pudo.
     */
    public function failed(Request $request, PrintJob $printJob): PrintJobResource
    {
        $validado = $request->validate([
            // Obligatorio: un trabajo fallido sin motivo deja a quien mira la pantalla sin saber si hay que poner papel,
            // encender la impresora o revisar la red.
            'error' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        return new PrintJobResource(
            $this->queue->markFailed($printJob, $this->agent($request), $validado['error'])->load('printer'),
        );
    }

    private function agent(Request $request): PrintAgent
    {
        /** @var PrintAgent $agent */
        $agent = $request->attributes->get(AuthenticatePrintAgent::ATRIBUTO);

        return $agent;
    }
}
