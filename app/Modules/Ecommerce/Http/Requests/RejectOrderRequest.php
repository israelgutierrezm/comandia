<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rechazar un pedido pagado (Iteración 8, Tanda D). Rechazar REEMBOLSA al cliente, así que el motivo es
 * obligatorio: queda en el pedido (auditable) y es lo que se le explica al cliente. Antes el controlador lo
 * leía de la petición sin validar, y un rechazo accidental —el «Cancelar» del prompt mandaba vacío—
 * reembolsaba sin motivo alguno.
 */
final class RejectOrderRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', 'max:300'],
        ];
    }
}
