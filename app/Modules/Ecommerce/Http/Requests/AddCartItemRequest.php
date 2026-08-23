<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Agrega un artículo al carrito de la tienda. El artículo y la sucursal viajan por ULID; el servicio valida que estén en
 * la tienda y que la sucursal la atienda.
 */
final class AddCartItemRequest extends FormRequest
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
            'article_ulid' => ['required', 'string', 'size:26'],
            'branch_ulid' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
