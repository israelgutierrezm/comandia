<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Agrega un widget a un tablero. El tipo decide qué campos importan: número/semáforo usan `measure_key`; barras/top-N
 * usan `dimension_key` + `measure_key`; el semáforo además un `period`. El motor valida después que la medida/dimensión
 * existan en el reporte.
 */
final class StoreWidgetRequest extends FormRequest
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
            'report_key' => ['required', 'string', 'max:80'],
            'visualization' => ['required', Rule::in(['numero', 'semaforo', 'barras', 'topn'])],
            'title' => ['required', 'string', 'max:80'],
            'measure_key' => ['nullable', 'string', 'max:40'],
            'dimension_key' => ['nullable', 'string', 'max:40'],
            'period' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
            'top_n' => ['nullable', 'integer', 'min:1', 'max:50'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }
}
