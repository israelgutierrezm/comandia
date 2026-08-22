<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pide un export de un reporte. El formato es lo único obligatorio; el resto de los parámetros (agrupación, filtros) son
 * los mismos del reporte y los valida el motor cuando el job corre (ADR-006: whitelist).
 */
final class StoreExportRequest extends FormRequest
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
            'format' => ['required', 'string', 'in:pdf,xlsx,csv'],
        ];
    }
}
