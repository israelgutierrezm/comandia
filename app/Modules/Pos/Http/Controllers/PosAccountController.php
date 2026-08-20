<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Application\AccountWorkflow;
use App\Modules\Pos\Application\CaptureOrderItems;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Http\Requests\CaptureOrderRequest;
use App\Modules\Pos\Http\Requests\OpenPosAccountRequest;
use App\Modules\Pos\Http\Resources\PosAccountResource;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Cuentas y captura de items (D28, §6.3).
 */
final class PosAccountController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountWorkflow $accounts,
        private readonly CaptureOrderItems $items,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PosAccount>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status', 'kind' => 'kind'],
            sortable: ['opened_at', 'folio'],

            // La etiqueta libre SÍ se busca: «Señor de lentes» es como se identifica una cuenta de barra, y buscarla es
            // la forma de encontrarla cuando hay quince abiertas.
            searchable: ['label'],
            defaultSort: '-opened_at',
            dateRanges: ['opened' => 'opened_at'],
            handledByCaller: ['branch', 'table', 'only_open', 'waiter'],
        );

        $builder = $query->apply(
            PosAccount::query()->with(['restaurantTable', 'waiter.user', 'waiter.employeeProfile']),
            $request,
        );

        // Con lo que abre la pantalla de piso: las cuentas vivas. Una cuenta pagada hace tres horas no le interesa a
        // nadie que esté atendiendo.
        if ($request->boolean('only_open')) {
            $builder->open();
        }

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        if ($request->filled('table')) {
            $builder->whereHas('restaurantTable', fn ($q) => $q->where('ulid', $request->string('table')));
        }

        // «Mis cuentas», que es la vista de un mesero: filtra por TITULAR y no por quien abrió, porque la propina y la
        // responsabilidad son del titular (D233).
        if ($request->filled('waiter')) {
            $builder->whereHas('waiter', fn ($q) => $q->where('ulid', $request->string('waiter')));
        }

        return PosAccountResource::collection($builder->paginate($query->perPage($request)));
    }

    public function show(PosAccount $posAccount): PosAccountResource
    {
        return new PosAccountResource($this->loaded($posAccount));
    }

    public function store(OpenPosAccountRequest $request): JsonResponse
    {
        $waiterId = $request->filled('waiter_ulid')
            ? TenantMembership::query()->where('ulid', $request->string('waiter_ulid'))->sole()->id
            : null;

        // Con mesa o sin mesa: son dos caminos distintos porque la mesa tiene que quedar ocupada, y una cuenta de barra
        // necesita su etiqueta para poder identificarse.
        if ($request->filled('table_ulid')) {
            $table = RestaurantTable::query()->where('ulid', $request->string('table_ulid'))->sole();

            $account = $this->accounts->openDineIn($table, $waiterId);
        } else {
            $branch = Branch::query()->where('ulid', $request->string('branch_ulid'))->sole();

            $account = $this->accounts->openWalkIn($branch, $request->string('label')->toString(), $waiterId);
        }

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_OPENED,
            auditable: $account,
            after: [
                'folio' => $account->folioNumber(),
                'display_name' => $account->displayName(),
                'waiter' => $account->waiter_membership_id,
            ],
        );

        return (new PosAccountResource($this->loaded($account)))->response()->setStatusCode(201);
    }

    /**
     * Captura una orden con sus líneas.
     */
    public function capture(CaptureOrderRequest $request, PosAccount $posAccount): JsonResponse
    {
        $this->assertVersion($request, $posAccount);

        $order = $this->items->capture($posAccount, $request->input('lines'));

        $this->audit->log(
            action: AuditAction::POS_ORDER_CAPTURED,
            auditable: $posAccount,
            after: [
                'folio' => $posAccount->folioNumber(),
                'order' => $order->sequence,
                'lines' => count($request->input('lines')),
            ],
        );

        return (new PosAccountResource($this->loaded($posAccount->refresh())))->response()->setStatusCode(201);
    }

    public function requestBill(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $account = $this->accounts->requestBill($posAccount);

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_BILL_REQUESTED,
            auditable: $account,
            after: ['folio' => $account->folioNumber(), 'total' => $account->total],
        );

        return new PosAccountResource($this->loaded($account));
    }

    public function close(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $account = $this->accounts->close($posAccount);

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_CLOSED,
            auditable: $account,
            after: ['folio' => $account->folioNumber(), 'total' => $account->total],
        );

        return new PosAccountResource($this->loaded($account));
    }

    /**
     * Vuelve a abrir una cuenta.
     *
     * La ruta exige `pos.accounts.reopen` incluso viniendo de `bill_requested`, donde es rutina. Podría afinarse para
     * pedir el permiso sólo desde `closed` —que es donde deshace un total ya impreso— y no se hace: dos permisos para la
     * misma acción según el estado de origen es la clase de regla que nadie recuerda al leer la ruta.
     */
    public function reopen(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $before = ['status' => $posAccount->status->value];

        $account = $this->accounts->reopen($posAccount);

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_REOPENED,
            auditable: $account,
            before: $before,
            after: ['folio' => $account->folioNumber()],
        );

        return new PosAccountResource($this->loaded($account));
    }

    public function cancel(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $validated = $request->validate([
            // Obligatorio, con el mismo argumento que en las mermas (D27) y los retiros: una cuenta cancelada sin motivo
            // es una venta que desapareció y nadie puede explicar.
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $account = $this->accounts->cancel($posAccount, $validated['reason']);

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_CANCELLED,
            auditable: $account,
            after: ['folio' => $account->folioNumber(), 'reason' => $validated['reason']],
        );

        return new PosAccountResource($this->loaded($account));
    }

    /**
     * El candado optimista (§11 de la Arquitectura).
     *
     * Quien opera manda la versión que leyó. Si no coincide, la cuenta cambió mientras la tenía en pantalla —alguien
     * agregó items o la cobró— y se responde 409 para que vuelva a cargar en lugar de escribir sobre lo que no vio.
     *
     * Es OPCIONAL en la petición a propósito: un cliente que no la manda acepta el riesgo, y exigirla rompería cualquier
     * integración que todavía no la conozca. La pantalla del POS sí la manda siempre.
     */
    private function assertVersion(Request $request, PosAccount $account): void
    {
        if (! $request->has('version')) {
            return;
        }

        if ((int) $request->integer('version') !== (int) $account->version) {
            throw PosAccountException::versionMismatch($account->displayName());
        }
    }

    private function loaded(PosAccount $account): PosAccount
    {
        return $account->load([
            'restaurantTable',
            'waiter.user',
            'waiter.employeeProfile',
            'openedBy.user',
            'openedBy.employeeProfile',
            'orders',
            'items.modifiers',
            'items.article',
            'items.preparationArea',
        ]);
    }
}
