<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Domain\Enums\PrinterConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de impresora.
 *
 * ## Qué SÍ se puede cambiar, y por qué es distinto de una terminal
 *
 * Una terminal no cambia de nombre de red porque su identidad ata un histórico de sesiones de caja. Una impresora, en
 * cambio, **cambia de sitio de verdad**: se quema y se sustituye por otra con distinta IP, se pasa de USB a red, se
 * cambia el rollo de 80 a 58 milímetros. Prohibir esos cambios obligaría a dar de baja la impresora y crear otra, y con
 * ella habría que reasignar todas las áreas que la citaban — trabajo inútil por una regla mal puesta.
 *
 * Un trabajo de impresión ya emitido no se ve afectado: lleva su destino resuelto en el momento de crearse.
 *
 * ## Lo que no cambia: sucursal y código
 *
 * La sucursal, porque una impresora es hardware que está físicamente en un sitio; moverla de sucursal en el sistema
 * sería describir una mudanza que nadie hizo. Y el código, porque es el identificador con el que las áreas y el agente
 * la nombran.
 */
final class UpdatePrinterRequest extends FormRequest
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
            'connection' => ['sometimes', 'required', Rule::enum(PrinterConnection::class)],
            'target' => ['sometimes', 'required', 'string', 'max:120'],
            'paper_width' => ['sometimes', 'required', 'integer', 'in:58,80'],
            'supports_cash_drawer' => ['sometimes', 'boolean'],

            'branch_ulid' => ['prohibited'],
            'code' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulid.prohibited' => 'Una impresora no cambia de sucursal: es hardware que está en un sitio.',
            'code.prohibited' => 'El código de una impresora no se cambia: es el nombre con el que la citan las áreas.',
            'paper_width.in' => 'El ancho de papel sólo puede ser 58 u 80 milímetros.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'connection' => 'el tipo de conexión',
            'target' => 'el destino',
            'paper_width' => 'el ancho de papel',
            'supports_cash_drawer' => 'el cajón de dinero',
        ];
    }
}
