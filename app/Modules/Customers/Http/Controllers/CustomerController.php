<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Application\CustomerPasswordResets;
use App\Modules\Customers\Http\Requests\SaveCustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerCredit;
use App\Modules\Customers\Jobs\NotifyCustomerEmailChanged;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Clientes: el mínimo que el cobro necesita (D235).
 *
 * ## Alta express desde el POS (D43)
 *
 * Nombre y nada más obligatorio. Todo lo demás es opcional **a propósito**: pedirle la razón social a alguien que está
 * pagando un café es exactamente lo que hace que nadie registre clientes — y un sistema de crédito sin clientes
 * registrados es el fiado en una libreta, que es lo que se está sustituyendo.
 *
 * El expediente completo —perfiles fiscales, direcciones, cumpleaños— llega en la Iteración 7.
 */
final class CustomerController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContextHolder $context,
        private readonly CustomerPasswordResets $passwordResets,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Customer>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['name', 'created_at'],

            // Nombre y teléfono: son las dos formas en que alguien busca a un cliente en el mostrador —«¿a nombre de
            // quién?», «del cinco cinco…»— y buscar sólo por nombre dejaría fuera la mitad de los casos.
            searchable: ['name', 'phone'],

            defaultSort: 'name',
            handledByCaller: ['with_debt'],
        );

        $builder = $query->apply(Customer::query()->with('credit'), $request);

        // «Quién me debe», que es la consulta con la que se abre la pantalla de cobranza.
        if ($request->boolean('with_debt')) {
            $builder->whereHas('credit', fn ($q) => $q->where('balance', '>', 0));
        }

        return CustomerResource::collection($builder->paginate($query->perPage($request)));
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load('credit'));
    }

    public function store(SaveCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create([
            'code' => $request->input('code'),
            'name' => (string) $request->string('name'),
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            'birthday' => $request->input('birthday'),
            'notes' => $request->input('notes'),
            'created_by_membership_id' => (int) $this->context->get()->membership?->id,
        ])->refresh();

        // La fila de crédito nace con el cliente, en cero y HABILITADA.
        //
        // Podría crearse al asignar el primer límite y sería peor: cada sitio que consulta el saldo tendría que
        // contemplar «todavía no hay fila», y ese `null` acabaría interpretándose como cero en unos lados y como error
        // en otros. Con límite cero, «no puede fiar» sale del propio dato.
        CustomerCredit::create(['customer_id' => $customer->id]);

        $this->audit->log(
            action: AuditAction::CUSTOMER_CREATED,
            auditable: $customer,
            after: ['name' => $customer->name, 'phone' => $customer->phone],
        );

        return (new CustomerResource($customer->load('credit')))->response()->setStatusCode(201);
    }

    public function update(SaveCustomerRequest $request, Customer $customer): CustomerResource
    {
        $antes = $customer->only(['name', 'phone', 'email', 'code', 'status']);
        $correoAntes = $customer->email;

        // Una sola transacción: el cambio, su rastro y la revocación del enlace quedan juntos o no quedan. El aviso al
        // correo anterior se encola al confirmar (`after_commit`): si la cola falla, el cambio ya tiene su bitácora.
        DB::transaction(function () use ($request, $customer, $antes, $correoAntes): void {
            $customer->update($request->only(['code', 'name', 'phone', 'email', 'birthday', 'notes', 'status']));

            $this->audit->log(
                action: AuditAction::CUSTOMER_UPDATED,
                auditable: $customer,
                before: $antes,
                after: $customer->only(['name', 'phone', 'email', 'code', 'status']),
            );

            $this->containStoreAccount($customer, $correoAntes);
        });

        return new CustomerResource($customer->refresh()->load('credit'));
    }

    /**
     * El cliente entra a la tienda en línea con su correo (D381). Si el negocio se lo cambia, el enlace de recuperación
     * que tuviera vivo deja de servir y se le avisa al correo ANTERIOR —es lo único que le avisa a la víctima si quien lo
     * cambió no debía—. Si lo inactiva, el enlace también muere; sus sesiones caen solas en `CustomerSession`.
     *
     * El rastro con el actor real ya lo deja `CUSTOMER_UPDATED`.
     */
    private function containStoreAccount(Customer $customer, ?string $correoAntes): void
    {
        $cambioCorreo = mb_strtolower((string) $correoAntes) !== mb_strtolower((string) $customer->email);

        if ($cambioCorreo || ! $customer->isActive()) {
            $this->passwordResets->revokeFor($customer);
        }

        if ($cambioCorreo && $customer->hasCredentials() && $correoAntes !== null) {
            NotifyCustomerEmailChanged::dispatch((int) $customer->tenant_id, (int) $customer->id, $correoAntes)->afterCommit();
        }
    }
}
