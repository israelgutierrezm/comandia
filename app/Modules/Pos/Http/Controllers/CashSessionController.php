<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Application\BuildSessionCutReport;
use App\Modules\Pos\Application\CashSessionWorkflow;
use App\Modules\Pos\Http\Requests\CloseCashSessionRequest;
use App\Modules\Pos\Http\Requests\DeclareCashRequest;
use App\Modules\Pos\Http\Requests\OpenCashSessionRequest;
use App\Modules\Pos\Http\Requests\WithdrawCashRequest;
use App\Modules\Pos\Http\Resources\PosSessionResource;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Sesiones de caja (§6.3).
 */
final class CashSessionController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CashSessionWorkflow $sessions,
        private readonly BuildSessionCutReport $cut,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PosSession>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['opened_at', 'closed_at'],

            // Sin búsqueda de texto: una sesión se busca por folio, por terminal o por fecha, y el folio es un filtro
            // exacto. Declararlo vacío hace que `?search=` se rechace en lugar de ignorarse (D182).
            searchable: [],
            defaultSort: '-opened_at',
            dateRanges: ['opened' => 'opened_at'],
            handledByCaller: ['terminal', 'only_open'],
        );

        $builder = $query->apply(
            PosSession::query()->with([
                'branch',
                'terminal',
                'openedBy.user',
                'openedBy.employeeProfile',
                'closedBy.user',
                'closedBy.employeeProfile',
            ]),
            $request,
        );

        // «Qué cajas están abiertas ahora», que es con lo que abre la pantalla del gerente: un turno cerrado hace tres
        // semanas no le interesa a nadie que esté trabajando.
        if ($request->boolean('only_open')) {
            $builder->open();
        }

        if ($request->filled('terminal')) {
            $builder->whereHas('terminal', fn ($q) => $q->where('ulid', $request->string('terminal')));
        }

        return PosSessionResource::collection($builder->paginate($query->perPage($request)));
    }

    public function show(PosSession $posSession): PosSessionResource
    {
        return new PosSessionResource($this->loaded($posSession));
    }

    /**
     * La sesión abierta de una terminal, si la hay.
     *
     * Es la primera petición que hace la pantalla de caja al arrancar: sin turno abierto no se puede cobrar (§6.3), así
     * que la interfaz necesita saberlo antes de pintar nada. Devuelve `data: null` en lugar de 404 porque «no hay turno»
     * es una respuesta legítima y no un error — un 404 haría que la pantalla tratara el caso normal como fallo.
     */
    public function current(Request $request, Terminal $terminal): JsonResponse
    {
        $session = PosSession::query()->open()->where('terminal_id', $terminal->id)->first();

        return new JsonResponse([
            'data' => $session === null ? null : new PosSessionResource($this->loaded($session)),
        ]);
    }

    public function open(OpenCashSessionRequest $request): JsonResponse
    {
        $terminal = Terminal::query()->where('ulid', $request->string('terminal_ulid'))->sole();

        $session = $this->sessions->open($terminal, $request->string('opening_float')->toString());

        $this->audit->log(
            action: AuditAction::CASH_SESSION_OPENED,
            auditable: $session,
            after: [
                'folio' => $session->folioNumber(),
                'terminal' => $terminal->code,
                'opening_float' => $session->opening_float,
            ],
        );

        return (new PosSessionResource($this->loaded($session)))->response()->setStatusCode(201);
    }

    /**
     * Declarar lo que hay: precorte o cierre.
     */
    public function declare(DeclareCashRequest $request, PosSession $posSession): PosSessionResource
    {
        $moment = $request->string('moment')->toString();

        /** @var array<string, string> $amounts */
        $amounts = [];

        foreach ($request->input('declarations') as $renglon) {
            $amounts[$renglon['payment_method_ulid']] = (string) $renglon['declared_amount'];
        }

        $this->sessions->declare($posSession, $moment, $amounts);

        $this->audit->log(
            action: $moment === 'precount' ? AuditAction::CASH_SESSION_PRECOUNTED : AuditAction::CASH_SESSION_DECLARED,
            auditable: $posSession,
            after: ['folio' => $posSession->folioNumber(), 'moment' => $moment, 'methods' => count($amounts)],
        );

        return new PosSessionResource($this->loaded($posSession->refresh()));
    }

    public function withdraw(WithdrawCashRequest $request, PosSession $posSession): JsonResponse
    {
        $withdrawal = $this->sessions->withdraw(
            $posSession,
            $request->string('amount')->toString(),
            $request->string('reason')->toString(),
            $request->filled('authorization_token') ? $request->string('authorization_token')->toString() : null,
        );

        $this->audit->log(
            action: AuditAction::CASH_WITHDRAWAL_REGISTERED,
            auditable: $withdrawal,
            after: [
                'folio' => $posSession->folioNumber(),
                'amount' => $withdrawal->amount,
                'reason' => $withdrawal->reason,
            ],

            // Quién autorizó con su PIN, distinto de quién lo hizo: es la columna que existe justo para esto (D172).
            authorizedBy: $withdrawal->authorizedBy,
        );

        return new JsonResponse([
            'data' => [
                'ulid' => $withdrawal->ulid,
                'amount' => $withdrawal->amount,
                'reason' => $withdrawal->reason,
                'created_at' => $withdrawal->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function close(CloseCashSessionRequest $request, PosSession $posSession): PosSessionResource
    {
        $session = $this->sessions->close(
            $posSession,
            $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        $this->audit->log(
            action: AuditAction::CASH_SESSION_CLOSED,
            auditable: $session,
            after: ['folio' => $session->folioNumber()],
        );

        return new PosSessionResource($this->loaded($session));
    }

    /**
     * Las relaciones que el recurso necesita, en un solo sitio.
     *
     * Repetirlas en cada método es cómo aparece un N+1 en el método que alguien añade después: se copia la firma pero no
     * la lista.
     */
    private function loaded(PosSession $session): PosSession
    {
        return $session->load([
            'branch',
            'terminal',
            'openedBy.user',
            'openedBy.employeeProfile',
            'closedBy.user',
            'closedBy.employeeProfile',
            'precountedBy.user',
            'precountedBy.employeeProfile',
            'declarations.paymentMethod',
            'withdrawals',
        ]);
    }

    /**
     * El corte de la caja: esperado contra declarado, método por método.
     *
     * ## Exige `finance.cuts.view`, y ése es TODO el mecanismo del precorte ciego
     *
     * El precorte es ciego porque quien cuenta no ve el esperado. No hace falta una versión recortada de este reporte:
     * basta que declarar (`pos.sessions.precount`) y ver el corte (`finance.cuts.view`) sean permisos distintos.
     *
     * Es preferible a un endpoint que devolviera «a veces con esperado y a veces sin él» según quién pregunte: esa
     * variante acabaría filtrándolo por un descuido de la pantalla, y el valor entero del precorte ciego es que el
     * número no se pueda ver antes de contar.
     *
     * ## Se puede mirar con la caja ABIERTA
     *
     * No es una foto del cierre, es la cuenta de ahora — y quien supervisa a media tarde quiere ver cómo va. Lo declarado
     * estará vacío hasta que alguien cuente, y eso se lee como lo que es.
     */
    public function cut(PosSession $posSession): JsonResponse
    {
        return new JsonResponse([
            'data' => array_merge(
                ['session' => $posSession->folioNumber(), 'status' => $posSession->status->value],
                $this->cut->forSession($posSession),
            ),
        ]);
    }
}
