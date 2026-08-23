<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Programa un reporte (Tanda D3). El reporte, formato y frecuencia son obligatorios; la agrupación es opcional. El motor
 * valida la agrupación contra la whitelist del reporte al correr, y el controlador verifica que quien programa tenga el
 * permiso del reporte —programar no otorga acceso a datos que no se pueden ver—.
 */
final class StoreScheduledReportRequest extends FormRequest
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
            'format' => ['required', 'string', 'in:pdf,xlsx,csv'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'group_by' => ['nullable', 'string', 'max:120'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email', 'max:191', 'distinct'],
        ];
    }
}
