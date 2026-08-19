<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Finance\Domain\Enums\PaymentMethodKind;
use App\Modules\Finance\Http\Requests\StorePaymentMethodRequest;
use App\Modules\Finance\Http\Requests\UpdatePaymentMethodRequest;
use App\Modules\Finance\Http\Resources\PaymentMethodResource;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Métodos de pago del negocio (§6.3, D232).
 */
final class PaymentMethodController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PaymentMethod>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status', 'kind' => 'kind'],
            sortable: ['sort_order', 'name', 'code'],
            searchable: ['name', 'code'],

            // Por `sort_order` y no por nombre: es el orden de los botones de la caja, y la interfaz que los pinta no
            // debería tener que reordenar lo que el servidor ya sabe ordenar.
            defaultSort: 'sort_order',
        );

        $methods = $query->apply(PaymentMethod::query(), $request);

        return PaymentMethodResource::collection($methods->paginate($query->perPage($request)));
    }

    public function show(PaymentMethod $paymentMethod): PaymentMethodResource
    {
        return new PaymentMethodResource($paymentMethod);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $method = PaymentMethod::create([
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),

            // Siempre `custom`: las otras cuatro naturalezas las siembra el sistema. Un segundo método de naturaleza
            // `cash` daría dos fuentes de efectivo esperado y el corte dejaría de poder explicarse.
            'kind' => PaymentMethodKind::Custom,

            'affects_cash_drawer' => $request->boolean('affects_cash_drawer'),
            'requires_reference' => $request->boolean('requires_reference'),
            'allows_change' => $request->boolean('allows_change'),
            'sort_order' => $request->integer('sort_order', 100),
        ]);

        $this->audit->log(
            action: AuditAction::PAYMENT_METHOD_CREATED,
            auditable: $method,
            after: $method->only(['code', 'name', 'kind', 'affects_cash_drawer', 'requires_reference', 'allows_change', 'status']),
        );

        return (new PaymentMethodResource($method->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        $campos = ['name', 'affects_cash_drawer', 'requires_reference', 'allows_change', 'sort_order'];
        $before = $paymentMethod->only($campos);

        // Si es del sistema y se intenta tocar algo congelado, el modelo lanza y el proveedor lo traduce a 422 con el
        // motivo en español. No se comprueba aquí para no tener la regla en dos sitios.
        $paymentMethod->update($request->safe()->all());

        $this->audit->log(
            action: AuditAction::PAYMENT_METHOD_UPDATED,
            auditable: $paymentMethod,
            before: $before,
            after: $paymentMethod->only($campos),
        );

        return new PaymentMethodResource($paymentMethod->refresh());
    }

    /**
     * Activar o desactivar.
     *
     * ## Por qué no se puede desactivar el último método activo
     *
     * Porque un negocio sin métodos de pago activos **no puede cobrar**, y el error aparecería en la caja, con un
     * cliente esperando y sin que nadie relacione las dos cosas. Es el tipo de configuración que se rompe sola: alguien
     * desactiva la tarjeta hoy, el efectivo la semana que viene «para probar», y el sábado la fila no avanza.
     *
     * Se responde **409** y no 422: no hay nada que corregir en el cuerpo de la petición — el problema es el estado del
     * negocio. Es el mismo criterio con el que D170 eligió 409 para la autorización pendiente.
     */
    public function toggle(PaymentMethod $paymentMethod): PaymentMethodResource
    {
        $activando = ! $paymentMethod->isActive();

        if (! $activando) {
            $activos = PaymentMethod::query()->active()->count();

            if ($activos <= 1) {
                throw new ConflictHttpException(
                    'Es el único método de pago activo: desactivarlo dejaría al negocio sin poder cobrar. '
                    .'Activa otro antes de dar de baja éste.'
                );
            }
        }

        $before = ['status' => $paymentMethod->status->value];

        $paymentMethod->update([
            'status' => $activando ? OperationalStatus::Active : OperationalStatus::Inactive,
        ]);

        $this->audit->log(
            action: AuditAction::PAYMENT_METHOD_UPDATED,
            auditable: $paymentMethod,
            before: $before,
            after: ['status' => $paymentMethod->refresh()->status->value],
        );

        return new PaymentMethodResource($paymentMethod);
    }
}
