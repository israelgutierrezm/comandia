<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Infrastructure\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guarda la configuración de la tienda. El slug es único **globalmente** (la ruta pública lo usa para resolver el negocio),
 * ignorando la propia tienda.
 */
final class SaveStoreRequest extends FormRequest
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
        $currentId = Store::query()->value('id');

        return [
            'slug' => ['required', 'string', 'alpha_dash', 'min:3', 'max:80', Rule::unique('stores', 'slug')->ignore($currentId)],
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'theme_primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branch_ulids' => ['present', 'array'],
            'branch_ulids.*' => ['string', 'size:26'],
        ];
    }
}
