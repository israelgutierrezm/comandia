<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cancelar items de una cuenta.
 *
 * ## El motivo y el destino son `nullable` aquí, y obligatorios en el servicio
 *
 * Parece al revés y no lo es. Sólo son obligatorios **si algo ya estaba comandado**, y eso el Form Request no lo sabe
 * sin consultar el estado de cada item — que es exactamente lo que el servicio hace, con la fila bloqueada, dentro de la
 * transacción. Validarlo aquí exigiría leer los items sin bloqueo y volver a leerlos después: dos lecturas, dos
 * respuestas posibles, y una ventana en medio.
 *
 * Así que el contrato es: la forma se valida aquí, la regla de negocio la impone el dominio. El 409 que sale del servicio
 * dice exactamente qué falta.
 */
final class CancelOrderItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'version' => ['sometimes', 'integer', 'min:0'],

            'item_ulids' => ['required', 'array', 'min:1', 'max:50'],
            'item_ulids.*' => [
                'required', 'string', 'size:26',
                Rule::exists('pos_order_items', 'ulid')->where('tenant_id', $tenantId),
            ],

            'reason' => ['nullable', 'string', 'min:3', 'max:300'],

            // `waste` o `restock`, y no una lista abierta: de esto depende que el inventario registre una merma o
            // devuelva el producto, así que un valor libre movería existencias a ciegas.
            'destination' => ['nullable', 'string', 'in:waste,restock'],

            // El token de la concesión de PIN, no el PIN. El PIN se cambia por un token en su propio endpoint y nunca
            // viaja en la petición de negocio (ADR-008).
            'authorization_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_ulids' => 'items a cancelar',
            'reason' => 'motivo',
            'destination' => 'destino del producto',
        ];
    }
}
