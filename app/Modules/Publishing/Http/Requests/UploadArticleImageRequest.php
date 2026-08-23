<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sube una imagen a la galería de un artículo. Se valida tipo y tamaño: una vitrina no acepta cualquier archivo.
 */
final class UploadArticleImageRequest extends FormRequest
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
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'], // 4 MB
            'alt_text' => ['nullable', 'string', 'max:160'],
        ];
    }
}
