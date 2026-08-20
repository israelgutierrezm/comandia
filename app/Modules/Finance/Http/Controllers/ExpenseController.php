<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\RegisterExpense;
use App\Modules\Finance\Domain\Enums\ExpenseSource;
use App\Modules\Finance\Domain\Exceptions\NoOpenCashSessionException;
use App\Modules\Finance\Http\Requests\StoreExpenseRequest;
use App\Modules\Finance\Http\Resources\ExpenseResource;
use App\Modules\Finance\Infrastructure\Models\Expense;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Domain\Contracts\CashSessionProbe;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Los gastos (§6.5).
 *
 * ## Dos permisos, no uno
 *
 * `finance.expenses.create_from_cash` y `create_outside_cash` son decisiones distintas: el cajero puede pagar los
 * garrafones con dinero del cajón, y no por eso debería poder registrar la renta del local como gasto del negocio. Un
 * permiso único obligaría a dárselos los dos o ninguno.
 */
final class ExpenseController
{
    use AssertsBranchScope;

    public function __construct(
        private readonly RegisterExpense $expenses,
        // El contrato del KERNEL, no un servicio del punto de venta: `Finance` no conoce a `Pos`, que ya depende de
        // él. Ver `CashSessionProbe`.
        private readonly CashSessionProbe $sessions,

        // Para la comprobación condicional del permiso de gasto FUERA de caja, que no cabe en el middleware de la ruta:
        // depende del `source` que venga en el cuerpo. Va por el servicio de contexto y NUNCA por `$user->can()` —
        // Spatie suma roles y aquí opera el rol activo (D9).
        private readonly Authorize $authorize,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Expense>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['source' => 'source'],
            sortable: ['occurred_at', 'amount'],
            searchable: ['description'],
            defaultSort: '-occurred_at',
            dateRanges: ['occurred' => 'occurred_at'],
            handledByCaller: ['branch', 'category', 'session'],
        );

        $builder = $query->apply(
            Expense::query()->with([
                'category',
                'branch',
                'method',
                'createdBy.user',
                'createdBy.employeeProfile',
                'authorizedBy.user',
                'authorizedBy.employeeProfile',
            ]),
            $request,
        );

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        if ($request->filled('category')) {
            $builder->whereHas('category', fn ($q) => $q->where('ulid', $request->string('category')));
        }

        // «Qué salió de esta caja», que es la consulta del arqueo.
        if ($request->filled('session')) {
            // Se traduce el ULID a id por el contrato del kernel: `Finance` no puede consultar `pos_sessions` ni
            // declarar una relación hacia ella sin cerrar un ciclo.
            $builder->where('pos_session_id', $this->sessions->sessionIdByUlid($request->string('session')->toString()));
        }

        return ExpenseResource::collection($builder->paginate($query->perPage($request)));
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $branch = Branch::query()->where('ulid', $request->string('branch_ulid'))->sole();

        // La sucursal viene del CUERPO: sin esto, un gasto se registra contra la caja de otra sucursal y le
        // descuadra el corte a un cajero que no lo capturó.
        $this->assertBranchInScope((int) $branch->id);
        $category = ExpenseCategory::query()->where('ulid', $request->string('expense_category_ulid'))->sole();
        $source = ExpenseSource::from((string) $request->string('source'));

        // El permiso de la ruta cubre el gasto DESDE CAJA, que es el mínimo para llegar aquí. El de fuera de caja es
        // otra decisión —el cajero paga los garrafones, y no por eso debería registrar la renta del local— y se
        // comprueba contra el `source` recibido, que el middleware no puede ver.
        if ($source === ExpenseSource::OutsideCash) {
            $this->authorize->authorizeWrite('finance.expenses.create_outside_cash');
        }

        // La caja la resuelve el SERVIDOR, no el cliente. Aceptar un `pos_session_ulid` dejaría que alguien cargara un
        // gasto al turno de otro — el arqueo del cajero de la mañana descuadrado por el de la tarde.
        $sessionId = null;

        if ($source === ExpenseSource::CashSession) {
            // Sin turno abierto es un 409 y no un 422: los datos que llegaron son correctos y lo que hay que hacer es
            // abrir la caja, no corregir el formulario.
            $sessionId = $this->sessions->openSessionIdForBranch((int) $branch->id)
                ?? throw NoOpenCashSessionException::forExpense();
        }

        $expense = $this->expenses->register(
            branch: $branch,
            category: $category,
            source: $source,
            amount: (string) $request->string('amount'),
            description: (string) $request->string('description'),
            posSessionId: $sessionId,
            method: $request->filled('payment_method_ulid')
                ? PaymentMethod::query()->where('ulid', $request->string('payment_method_ulid'))->sole()
                : null,
            receiptPath: $request->input('receipt_path'),
            authorizationToken: $request->input('authorization_token'),
        );

        return (new ExpenseResource($this->loaded($expense)))->response()->setStatusCode(201);
    }

    public function show(Expense $expense): ExpenseResource
    {
        return new ExpenseResource($this->loaded($expense));
    }

    private function loaded(Expense $expense): Expense
    {
        return $expense->load([
            'category',
            'branch',
            'method',
            'createdBy.user',
            'createdBy.employeeProfile',
            'authorizedBy.user',
            'authorizedBy.employeeProfile',
        ]);
    }
}
