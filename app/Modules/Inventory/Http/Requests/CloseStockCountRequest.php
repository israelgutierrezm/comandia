<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre de un conteo.
 *
 * El cuerpo lleva sólo la concesión de PIN, y opcional: el servidor decide si hace falta, porque saberlo exige
 * valuar todas las diferencias contra el umbral de la sucursal — trabajo que el cliente no puede hacer.
 */
final class CloseStockCountRequest extends FormRequest
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
            'authorization_token' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'authorization_token' => 'la autorización',
        ];
    }
}
