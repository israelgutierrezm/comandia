<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos al marcar un pedido como ENVIADO (modo envío, ADR-013). Paquetería y número de guía son
 * opcionales: no todo negocio los captura, y no bloquean el avance del estado.
 */
final class ShipOrderRequest extends FormRequest
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
            'carrier' => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
        ];
    }
}
