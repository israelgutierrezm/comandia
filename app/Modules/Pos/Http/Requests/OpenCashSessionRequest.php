<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abrir caja.
 */
final class OpenCashSessionRequest extends FormRequest
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
            'terminal_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('terminals', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // Una terminal dada de baja no abre caja: el contexto ya rechaza su cabecera, y permitir abrir un
                    // turno en ella dejaría cobros atribuidos a una caja que el negocio retiró.
                    ->where('status', 'active'),
            ],

            // CERO es válido: una caja puede abrir sin cambio, y exigir un fondo obligaría a inventar una cifra. Lo que
            // no puede es ser negativo — hay un CHECK en la tabla además de esta regla.
            'opening_float' => ['required', 'numeric', 'gte:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terminal_ulid.required' => 'Indica en qué caja se abre el turno.',
            'terminal_ulid.exists' => 'Esa caja no existe o está dada de baja.',
            'opening_float.required' => 'Indica con cuánto efectivo abre la caja. Si abre sin cambio, captura cero.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'terminal_ulid' => 'la caja',
            'opening_float' => 'el fondo',
        ];
    }
}
