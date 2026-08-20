<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Application\RegisterCreditRepayment;
use App\Modules\Customers\Http\Resources\CustomerCreditMovementResource;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerCredit;
use App\Modules\Customers\Infrastructure\Models\CustomerCreditMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * El crédito de un cliente: su límite, su estado de cuenta y sus abonos.
 */
final class CustomerCreditController
{
    use AssertsBranchScope;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RegisterCreditRepayment $repayments,
    ) {}

    /**
     * El estado de cuenta: los movimientos del cliente, más recientes primero.
     *
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, CustomerCreditMovement>>
     */
    public function statement(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['type' => 'type'],
            sortable: ['created_at'],
            searchable: [],
            defaultSort: '-created_at',
            dateRanges: ['created' => 'created_at'],
        );

        $builder = $query->apply(
            CustomerCreditMovement::query()->where('customer_id', $customer->id),
            $request,
        );

        return CustomerCreditMovementResource::collection($builder->paginate($query->perPage($request)));
    }

    /**
     * Fija el límite y si el crédito está habilitado.
     *
     * ## Son dos cosas y no una
     *
     * Un cliente que se atrasó **no pierde su límite**, pierde el permiso de usarlo. Volver a habilitarlo no exige
     * recapturar nada, y el historial deja ver que el límite nunca cambió — que es distinto de habérselo bajado a cero
     * y volver a subirlo.
     */
    public function update(Request $request, Customer $customer): CustomerResource
    {
        $validado = $request->validate([
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $credito = CustomerCredit::query()->where('customer_id', $customer->id)->sole();

        $antes = $credito->only(['credit_limit', 'is_enabled']);

        $credito->update([
            'credit_limit' => $validado['credit_limit'],
            'is_enabled' => $validado['is_enabled'],
        ]);

        $this->audit->log(
            action: AuditAction::CUSTOMER_CREDIT_UPDATED,
            auditable: $customer,
            before: $antes,
            after: $credito->refresh()->only(['credit_limit', 'is_enabled']),
        );

        return new CustomerResource($customer->load('credit'));
    }

    /**
     * Un abono.
     *
     * Afecta cajón al ocurrir (§6.3): el dinero entra al efectivo del turno, así que exige caja abierta y el arqueo lo
     * conoce. Sin esto, un turno que recibió dos mil pesos de fiado daría dos mil de más sin explicación.
     */
    public function repay(Request $request, Customer $customer): JsonResponse
    {
        $validado = $request->validate([
            'branch_ulid' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'payment_method_ulid' => ['required', 'string', 'size:26'],
        ]);

        $branch = Branch::query()->where('ulid', $validado['branch_ulid'])->sole();

        // El abono entra al cajón de una sucursal: es dinero, y la sucursal decide en QUÉ corte aparece. Sin esta
        // comprobación se abona en la caja de una sucursal que quien cobra no opera, y allá el arqueo sale sobrado
        // sin que nadie sepa de dónde.
        $this->assertBranchInScope((int) $branch->id);

        $metodo = PaymentMethod::query()->where('ulid', $validado['payment_method_ulid'])->sole();

        $movimiento = $this->repayments->register(
            customer: $customer,
            amount: $validado['amount'],
            branchId: (int) $branch->id,
            paymentMethodId: (int) $metodo->id,
        );

        return (new CustomerCreditMovementResource($movimiento))->response()->setStatusCode(201);
    }
}
