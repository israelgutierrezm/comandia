<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crea el menú de una sucursal. El slug es único **globalmente** (la ruta pública lo usa para resolver el negocio), así que
 * la regla `unique` consulta la tabla sin scope de tenant a propósito.
 */
final class StoreDigitalMenuRequest extends FormRequest
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
            'branch_ulid' => ['required', 'string', 'size:26'],
            'slug' => ['required', 'string', 'alpha_dash', 'min:3', 'max:80', Rule::unique('digital_menus', 'slug')],
        ];
    }
}
