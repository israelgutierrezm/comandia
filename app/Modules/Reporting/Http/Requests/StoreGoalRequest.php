<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fija (o ajusta) una meta de reporte. El controlador hace updateOrCreate por su alcance, así que enviarla dos veces la
 * actualiza en vez de duplicarla.
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
            'target_value' => ['required', 'numeric', 'gte:0'],
            'direction' => ['required', Rule::in(['higher_better', 'lower_better'])],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
