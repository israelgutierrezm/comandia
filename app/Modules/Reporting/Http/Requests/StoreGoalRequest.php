<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fija (o ajusta) una meta de reporte. El controlador hace updateOrCreate por su alcance, así que enviarla dos veces la
 * actualiza en vez de duplicarla.
 *
 * ## El valor, con el tope de su columna
 *
 * `target_value` es DECIMAL(14,4): diez enteros y cuatro decimales. Sin tope, un valor que no cabía llegaba a la base y
 * MySQL lo rechazaba con un 500. Los decimales se validan ANTES que las comparaciones y con `bail`: una notación
 * científica con un exponente enorme (`1e2000`) hace que comparar reviente dentro del validador, y así se corta antes
 * con un 422.
 */
final class StoreGoalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_key' => ['required', 'string', 'max:80'],
            'measure_key' => ['required', 'string', 'max:40'],
            'branch_ulid' => ['nullable', 'string', 'size:26'],
            'period' => ['required', Rule::in(['day', 'week', 'month', 'year'])],
            'target_value' => ['bail', 'required', 'numeric', 'decimal:0,4', 'gte:0', 'max:9999999999.9999'],
            'direction' => ['required', Rule::in(['higher_better', 'lower_better'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_value.decimal' => 'El valor objetivo admite hasta cuatro decimales, sin notación científica.',
            'target_value.gte' => 'El valor objetivo no puede ser negativo.',
            'target_value.max' => 'El valor objetivo no puede pasar de 9,999,999,999.9999.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'report_key' => 'el reporte',
            'measure_key' => 'la medida',
            'branch_ulid' => 'la sucursal',
            'period' => 'el periodo',
            'target_value' => 'el valor objetivo',
            'direction' => 'la dirección',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
