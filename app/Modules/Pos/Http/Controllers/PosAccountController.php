<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Application\AccountOperations;
use App\Modules\Pos\Application\AccountWorkflow;
use App\Modules\Pos\Application\CancelOrderItems;
use App\Modules\Pos\Application\CaptureOrderItems;
use App\Modules\Pos\Application\ApplyDiscount;
use App\Modules\Pos\Application\ApplyPromotions;
use App\Modules\Pos\Application\ChargeAccount;
use App\Modules\Pos\Application\CommandOrder;
use App\Modules\Pos\Domain\Enums\PosDiscountKind;
use App\Modules\Pos\Domain\Enums\TakeoutDeliveryStatus;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Http\Requests\CancelOrderItemsRequest;
use App\Modules\Pos\Http\Requests\CaptureOrderRequest;
use App\Modules\Pos\Http\Requests\ApplyDiscountRequest;
use App\Modules\Pos\Http\Requests\ChargeAccountRequest;
use App\Modules\Pos\Http\Requests\OpenPosAccountRequest;
use App\Modules\Pos\Http\Resources\PosAccountResource;
use App\Modules\Pos\Http\Resources\PromotionPreviewResource;
use App\Modules\Pos\Http\Resources\PosTicketResource;
use App\Modules\Pos\Infrastructure\Models\PosOrder;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use App\Modules\Shared\Http\Query\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Cuentas y captura de items (D28, §6.3).
 */
final class PosAccountController
{
    use AssertsBranchScope;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountWorkflow $accounts,
        private readonly CaptureOrderItems $items,
        private readonly CommandOrder $commands,
        private readonly CancelOrderItems $cancellations,
        private readonly ChargeAccount $charges,
        private readonly ApplyDiscount $discounts,
        private readonly ApplyPromotions $promotions,
        private readonly AccountOperations $operations,
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
        // La sucursal llega por el CUERPO en los tres caminos, así que ninguno pasó por el middleware que comprueba
        // el alcance. El `tenant_id` no protege de esto: la sucursal ajena es del mismo negocio. De la cuenta cuelgan
        // después las órdenes y las comandas, que se imprimirían en la cocina de otra sucursal.
        if ($request->boolean('takeout')) {
            $branch = Branch::query()->where('ulid', $request->string('branch_ulid'))->sole();

            $this->assertBranchInScope((int) $branch->id);

            $account = $this->accounts->openTakeout($branch, $waiterId);
        } elseif ($request->filled('table_ulid')) {
            $table = RestaurantTable::query()->where('ulid', $request->string('table_ulid'))->sole();

            $this->assertBranchInScope((int) $table->branch_id, 'No tienes acceso a la sucursal de esa mesa.');

            $account = $this->accounts->openDineIn($table, $waiterId);
        } else {
            $branch = Branch::query()->where('ulid', $request->string('branch_ulid'))->sole();

            $this->assertBranchInScope((int) $branch->id);

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

    /**
     * Fija la cantidad de una línea aún sin comandar (el «−»/«+» del panel «pendiente por enviar»). Es corregir lo que
     * uno acaba de capturar, así que va con el permiso de tomar la orden, sin PIN. Para quitarla del todo, el `×` usa la
     * cancelación de no-comandados.
     */
    public function setItemQuantity(Request $request, PosAccount $posAccount, string $itemUlid): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $validado = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
        ]);

        $account = $this->items->setQuantity($posAccount, $itemUlid, (string) $validado['quantity']);

        return new PosAccountResource($this->loaded($account));
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
     * Comandar: manda a preparar lo que se capturó.
     *
     * Devuelve las **comandas emitidas**, no la cuenta, porque es lo que la pantalla necesita para decir «salieron dos
     * papeles: cocina y barra». Una lista vacía es una respuesta legítima y frecuente: significa que no había nada
     * pendiente, y es lo que pasa cuando el mesero vuelve a picar el botón porque no vio la confirmación.
     */
    public function command(Request $request, PosAccount $posAccount, string $orderUlid): JsonResponse
    {
        $this->assertVersion($request, $posAccount);

        $order = PosOrder::query()
            ->where('ulid', $orderUlid)
            ->where('pos_account_id', $posAccount->id)
            ->first()
            ?? throw PosAccountException::orderNotInAccount($posAccount->displayName());

        $comandas = $this->commands->command($order);

        // Se audita sólo si algo salió. Un asiento por cada intento sin efecto llenaría la bitácora de ruido justo en la
        // acción que más se repite por nerviosismo.
        if ($comandas !== []) {
            $this->audit->log(
                action: AuditAction::POS_ORDER_COMMANDED,
                auditable: $posAccount,
                after: [
                    'folio' => $posAccount->folioNumber(),
                    'order' => $order->sequence,
                    'tickets' => count($comandas),
                    'areas' => array_map(fn ($t): ?int => $t->preparation_area_id, $comandas),
                ],
            );
        }

        return PosTicketResource::collection(
            collect($comandas)->map(fn ($t) => $t->load(['account', 'order', 'preparationArea', 'items.item'])),
        )->response()->setStatusCode(201);
    }

    /**
     * Quitar items de la cuenta.
     *
     * Un solo endpoint para las dos formas —borrar lo no comandado y cancelar lo comandado— porque desde fuera es la
     * misma intención: «quita esto». Lo que cambia es lo que el sistema tiene que exigir, y eso lo decide el estado de
     * cada item, no el cliente. Dos endpoints obligarían a la pantalla a llevar su propia copia de la frontera, y una
     * pantalla desactualizada mandaría al equivocado.
     */
    public function cancelItems(CancelOrderItemsRequest $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $account = $this->cancellations->cancel(
            account: $posAccount,
            itemUlids: $request->input('item_ulids'),
            reason: $request->input('reason'),
            destination: $request->input('destination'),
            authorizationToken: $request->input('authorization_token'),
        );

        return new PosAccountResource($this->loaded($account));
    }

    /**
     * Cobrar.
     *
     * Devuelve la cuenta entera y no sólo un acuse: quien cobra necesita ver el nuevo estado, lo que falta por pagar y
     * el cambio a devolver, y pedirlo en una segunda llamada dejaría una ventana en la que el cajero no sabe qué
     * entregar.
     */
    public function charge(ChargeAccountRequest $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $account = $this->charges->charge(
            $posAccount,
            $request->input('payments'),
            $request->input('fiscal_profile_ulid'),
        );

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_CHARGED,
            auditable: $account,
            after: [
                'folio' => $account->folioNumber(),
                'total' => $account->total,
                'paid_total' => $account->paid_total,
                'tip_total' => $account->tip_total,
                'change_total' => $account->change_total,
                'status' => $account->status->value,
            ],
        );

        return new PosAccountResource($this->loaded($account));
    }

    /**
     * La vista previa de promociones de la cuenta: qué se descontaría si se cobrara ahora (paso 11 del diseño, §6.3).
     *
     * No escribe nada —el resolver es puro— y por eso es un GET: la pantalla la consulta mientras se captura para
     * mostrar «2x1: -$45» antes de cobrar. Lo que queda grabado lo decide el cobro, una sola vez.
     *
     * El permiso es el de trabajar la cuenta (`pos.orders.create`), no uno propio: ver el precio con promoción es parte
     * de armar la cuenta, no una acción aparte.
     */
    public function promotionsPreview(PosAccount $posAccount): PromotionPreviewResource
    {
        $outcome = $this->promotions->preview($posAccount, CarbonImmutable::now());

        return new PromotionPreviewResource($outcome);
    }

    /**
     * Aplicar un descuento o una cortesía.
     *
     * No audita aquí: lo hace el servicio, que es el único que conoce el monto resuelto y a las dos personas. Es la
     * misma razón por la que la cancelación de items audita en su servicio.
     */
    public function discount(ApplyDiscountRequest $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $account = $this->discounts->apply(
            account: $posAccount,
            kind: PosDiscountKind::from((string) $request->string('kind')),
            value: (string) ($request->input('value') ?? '0'),
            reason: (string) $request->string('reason'),
            itemUlid: $request->input('item_ulid'),
            authorizationToken: $request->input('authorization_token'),
        );

        return new PosAccountResource($this->loaded($account));
    }

    /**
     * Dividir en partes iguales.
     *
     * Devuelve las SUBCUENTAS y no la madre: es lo que la pantalla necesita para poner cuatro botones de cobro. La madre
     * conserva los items y su mesa, y queda pagada cuando todas sus partes lo están.
     */
    public function split(Request $request, PosAccount $posAccount): AnonymousResourceCollection
    {
        $this->assertVersion($request, $posAccount);

        $validado = $request->validate([
            // Entre 2 y 20. Uno no es dividir, y por encima de veinte es un error de dedo: repartir una cuenta en
            // cincuenta partes crearía cincuenta folios que nadie va a cobrar.
            'parts' => ['required', 'integer', 'min:2', 'max:20'],
        ]);

        $subcuentas = $this->operations->split($posAccount, (int) $validado['parts']);

        return PosAccountResource::collection(
            collect($subcuentas)->map(fn (PosAccount $c) => $this->loaded($c)),
        );
    }

    /**
     * Mover items a otra cuenta.
     */
    public function moveItems(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $validado = $request->validate([
            'target_account_ulid' => ['required', 'string', 'size:26'],
            'item_ulids' => ['required', 'array', 'min:1', 'max:50'],
            'item_ulids.*' => ['required', 'string', 'size:26'],
        ]);

        $destino = PosAccount::query()->where('ulid', $validado['target_account_ulid'])->sole();

        return new PosAccountResource($this->loaded(
            $this->operations->moveItems($posAccount, $destino, $validado['item_ulids']),
        ));
    }

    /**
     * Juntar esta cuenta en otra.
     *
     * La cuenta de la URL es el ORIGEN: es la que desaparece. Ponerla al revés haría que «juntar la cuenta 3 en la 4»
     * se escribiera sobre la 4, y quien lo lea en el historial tendría que recordar la convención.
     */
    public function merge(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $validado = $request->validate([
            'target_account_ulid' => ['required', 'string', 'size:26'],
        ]);

        $destino = PosAccount::query()->where('ulid', $validado['target_account_ulid'])->sole();

        return new PosAccountResource($this->loaded(
            $this->operations->merge($posAccount, $destino),
        ));
    }

    /**
     * Mover la cuenta a otra mesa, o asignarle una si venía de barra.
     *
     * «Nos pasamos a la mesa del fondo» ocurre en cada servicio, y hasta este paso la única salida era cancelar la
     * cuenta y volver a capturar todo — que además pide PIN por cada item ya comandado.
     */
    public function moveToTable(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $validado = $request->validate([
            'table_ulid' => ['required', 'string', 'size:26'],
        ]);

        $mesa = RestaurantTable::query()->where('ulid', $validado['table_ulid'])->sole();

        $antes = $posAccount->displayName();

        $account = $this->accounts->moveToTable($posAccount, $mesa);

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_MOVED_TABLE,
            auditable: $account,
            before: ['display_name' => $antes],
            after: ['display_name' => $account->displayName(), 'table' => $mesa->code],
        );

        return new PosAccountResource($this->loaded($account));
    }

    /**
     * Mover un pedido para llevar por sus estados de entrega.
     *
     * Es una acción aparte del cobro a propósito: `pos.takeout_payment_timing` decide si se cobra al ordenar o al
     * recoger, así que pagar y entregar son hechos distintos que ocurren en cualquier orden.
     */
    public function advanceDelivery(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $validado = $request->validate([
            'delivery_status' => ['required', 'string', 'in:ready,delivered'],
        ]);

        $antes = $posAccount->delivery_status?->value;

        $account = $this->accounts->advanceDelivery(
            $posAccount,
            TakeoutDeliveryStatus::from($validado['delivery_status']),
        );

        $this->audit->log(
            action: AuditAction::POS_TAKEOUT_DELIVERY_CHANGED,
            auditable: $account,
            before: ['delivery_status' => $antes],
            after: [
                'delivery_status' => $account->delivery_status?->value,
                'takeout_number' => $account->takeout_number,
            ],
        );

        return new PosAccountResource($this->loaded($account));
    }

    /**
     * Identifica la cuenta con un cliente.
     *
     * Hace falta antes de cobrar a crédito —un consumo fiado sin nombre es dinero que nadie va a cobrar— y sirve además
     * para el historial de consumos que llega en la Iteración 7.
     *
     * Se puede cambiar mientras la cuenta esté viva y no se puede quitar una vez fiada: el saldo del cliente ya lleva el
     * cargo, y desligarlo dejaría una deuda sin cuenta que la explique. Eso lo impide la regla de siempre — una cuenta
     * pagada ya no admite operaciones.
     */
    public function assignCustomer(Request $request, PosAccount $posAccount): PosAccountResource
    {
        $this->assertVersion($request, $posAccount);

        $validado = $request->validate([
            'customer_ulid' => ['required', 'string', 'size:26'],
        ]);

        if (! $posAccount->status->acceptsItems()) {
            throw PosAccountException::accountNotOperable(
                $posAccount->displayName(),
                $posAccount->status->label(),
            );
        }

        $customer = Customer::query()->where('ulid', $validado['customer_ulid'])->sole();

        $posAccount->update(['customer_id' => $customer->id]);

        $this->audit->log(
            action: AuditAction::POS_ACCOUNT_CUSTOMER_SET,
            auditable: $posAccount,
            after: ['folio' => $posAccount->folioNumber(), 'customer' => $customer->name],
        );

        return new PosAccountResource($this->loaded($posAccount->refresh()));
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

            // La orden de cada línea: la pantalla necesita saber QUÉ orden comandar, y sin precargarla el recurso la
            // leería perezosamente — con el lazy loading deshabilitado, eso es un 500.
            'items.order',
            'items.article',
            'items.preparationArea',
            'discounts.appliedBy.user',
            'discounts.appliedBy.employeeProfile',
            'discounts.authorizedBy.user',
            'discounts.authorizedBy.employeeProfile',
            'payments.method',
            'payments.tipTo.user',
            'payments.tipTo.employeeProfile',
        ]);
    }
}
