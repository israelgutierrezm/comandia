<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de método de pago.
 *
 * ## Lo que se puede cambiar depende de si es del sistema, y el modelo es quien lo impone
 *
 * Aquí se validan **formas**, no invariantes: que el nombre sea una cadena de 60, que el orden sea un entero. Quién
 * puede cambiar qué lo decide el modelo, porque un servicio de aplicación o un comando de consola no pasan por este
 * Form Request y el invariante tiene que valer igual — es la lección de D186 en la Iteración 3.
 *
 * El resultado para quien llama es un **422 con el motivo en español**, traducido por el proveedor del módulo, y no un
 * 500. Un método del sistema admite cambiar su estado y su orden en los botones; no admite cambiar su nombre, su
 * código, su naturaleza ni sus banderas.
 */
final class UpdatePaymentMethodRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:60'],

            'affects_cash_drawer' => ['sometimes', 'boolean'],
            'requires_reference' => ['sometimes', 'boolean'],
            'allows_change' => ['sometimes', 'boolean'],

            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],

            // El código no se cambia ni en un método propio: es la referencia estable con la que el diario y los
            // reportes lo agrupan, y renombrarlo dejaría los cortes históricos hablando de algo que ya no existe.
            'code' => ['prohibited'],
            'kind' => ['prohibited'],
            'is_system' => ['prohibited'],

            // El estado tiene su propio endpoint, como en el resto del sistema: dar de baja es una acción con
            // consecuencia y su propio asiento de auditoría, no un campo más de un formulario.
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'El código de un método de pago no se cambia: es como lo agrupan los cortes.',
            'kind.prohibited' => 'La naturaleza de un método de pago no se cambia.',
            'status.prohibited' => 'Para activar o desactivar un método usa su acción propia.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'affects_cash_drawer' => 'el efecto en el cajón',
            'requires_reference' => 'la referencia',
            'allows_change' => 'el cambio',
            'sort_order' => 'el orden',
        ];
    }
}
