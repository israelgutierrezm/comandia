<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Actualiza un menú: slug, publicación, precios visibles y tema. El slug sigue siendo único globalmente, ignorando el
 * propio menú.
 */
final class UpdateDigitalMenuRequest extends FormRequest
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
        $id = $this->route('digitalMenu')?->id;

        return [
            'slug' => ['required', 'string', 'alpha_dash', 'min:3', 'max:80', Rule::unique('digital_menus', 'slug')->ignore($id)],
            'is_active' => ['required', 'boolean'],
            'show_prices' => ['required', 'boolean'],
            'theme_primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_font' => ['nullable', 'string', 'max:60'],
        ];
    }
}
