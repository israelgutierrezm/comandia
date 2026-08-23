<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guarda los ajustes de tienda de un artículo: política de stock, visibilidad, SEO y precio por canal.
 */
final class SaveArticleStoreSettingRequest extends FormRequest
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
            'stock_policy' => ['required', Rule::in(ArticleStoreSetting::POLICIES)],
            'is_in_store' => ['required', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            // Dinero: DECIMAL(12,2). null = hereda el precio del Core.
            'channel_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
