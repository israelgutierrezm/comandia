<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Guarda la publicación de un artículo: descripción larga, orden y visibilidad. Las imágenes van por su propio endpoint.
 */
final class SaveArticlePublicationRequest extends FormRequest
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
            'long_description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_visible' => ['required', 'boolean'],
        ];
    }
}
