<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Printing\Application\PrintJobQueue;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Printing\Application\QueuePrintJob;
use App\Modules\Printing\Domain\Exceptions\CashDrawerRequiresAuthorizationException;
use App\Modules\Printing\Http\Resources\PrintJobResource;
use App\Modules\Printing\Infrastructure\Models\PrintJob;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * La pantalla de impresión: qué se mandó, qué salió y qué falló.
 *
 * Es la contrapartida de que un fallo de impresión no tumbe la venta: si el error no llega a la cara de quien opera,
 * tiene que llegar a algún sitio donde alguien lo vea.
 */
final class PrintJobController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrintJobQueue $queue,
        private readonly QueuePrintJob $jobs,
        private readonly ContextHolder $context,
        private readonly PinAuthorizationService $pin,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PrintJob>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status', 'kind' => 'kind'],
            sortable: ['created_at'],
            searchable: [],
            defaultSort: '-created_at',
            dateRanges: ['created' => 'created_at'],
            handledByCaller: ['branch', 'printer', 'only_open'],
        );

        $builder = $query->apply(
            PrintJob::query()->with(['printer', 'ticket']),
            $request,
        );

        // Con lo que abre la pantalla: lo que todavía puede necesitar a alguien. Un trabajo impreso hace dos horas no
        // le interesa a nadie que esté buscando por qué la cocina no recibe papeles.
        if ($request->boolean('only_open')) {
            $builder->whereIn('status', ['pending', 'claimed', 'failed']);
        }

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        if ($request->filled('printer')) {
            $builder->whereHas('printer', fn ($q) => $q->where('ulid', $request->string('printer')));
        }

        return PrintJobResource::collection($builder->paginate($query->perPage($request)));
    }

    public function show(PrintJob $printJob): PrintJobResource
    {
        return new PrintJobResource($printJob->load(['printer', 'ticket']));
    }

    /**
     * Vuelve a mandar el trabajo a la cola.
     *
     * Sirve para reintentar uno que falló y para liberar uno que un agente reclamó antes de morirse. Queda auditado
     * porque volver a sacar un papel de la cocina puede hacer que se prepare la comida dos veces — el mismo argumento
     * que la reimpresión de un ticket.
     */
    public function retry(PrintJob $printJob): PrintJobResource
    {
        $antes = $printJob->status->value;

        $trabajo = $this->queue->requeue($printJob);

        $this->audit->log(
            action: AuditAction::PRINT_JOB_RETRIED,
            auditable: $trabajo,
            before: ['status' => $antes, 'last_error' => $printJob->last_error],
            after: ['status' => $trabajo->status->value, 'attempts' => $trabajo->attempts],
        );

        return new PrintJobResource($trabajo->load(['printer', 'ticket']));
    }

    /**
     * Abre el cajón de dinero.
     *
     * ## Es una acción sensible y exige PIN (§6.3)
     *
     * Abrir el cajón fuera de un cobro es la forma más directa de sacar dinero sin que aparezca en ningún lado. §6.3 lo
     * pone en la lista de acciones que exigen PIN junto con los descuentos y la cancelación de comandado, y por la misma
     * razón: lo que se registra es **quién autorizó**, no quién tocó la pantalla.
     *
     * El motivo también es obligatorio. Un cajón abierto sin motivo, a las tres de la mañana, no se puede explicar en el
     * corte — y explicarlo es justo para lo que sirve el registro.
     */
    public function openDrawer(Request $request, Printer $printer): JsonResponse
    {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:200'],

            // El token de la concesión, no el PIN: el PIN se cambia por un token en su propio endpoint y nunca viaja en
            // la petición de negocio (ADR-008).
            'authorization_token' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $request->filled('authorization_token')) {
            throw CashDrawerRequiresAuthorizationException::forPrinter((string) $printer->name);
        }

        // Gasta la concesión y devuelve al AUTORIZADOR. Es a quien la bitácora tiene que nombrar: el actor real de una
        // acción sensible es quien la autorizó, no quien tocó la pantalla (§6.3).
        $autorizador = $this->pin->consume(
            (string) $request->string('authorization_token'),
            'pos.cash_drawer.open',
        );

        $actor = (int) ($this->context->get()->membership?->id ?? $autorizador->id);

        $trabajo = $this->jobs->forDrawer($printer, $validado['reason'], $autorizador->id);

        $this->audit->log(
            action: AuditAction::CASH_DRAWER_OPENED,
            auditable: $trabajo,
            after: [
                'printer' => $printer->name,
                'reason' => $validado['reason'],
                'requested_by_membership_id' => $actor,
                'authorized_by_membership_id' => $autorizador->id,
            ],
        );

        return (new PrintJobResource($trabajo->load('printer')))->response()->setStatusCode(201);
    }
}
