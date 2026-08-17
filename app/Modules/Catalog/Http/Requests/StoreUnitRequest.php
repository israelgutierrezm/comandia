<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\Enums\UnitDimension;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Alta de unidad de medida.
 *
 * La autorización la aplica el middleware `can.write` de la ruta, que evalúa el ROL ACTIVO (D9).
 * `authorize()` devuelve true a propósito: verificar aquí tentaría a usar `$this->user()->can()`, que
 * evalúa la suma de roles y está prohibido.
 */
final class StoreUnitRequest extends FormRequest
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
            // Único por tenant y en `ascii_bin` (D58): sin esa colación, `Kg` y `kg` serían la misma
            // fila para el índice y el tenant no entendería por qué su unidad "ya existe". La regla
            // `unique` se acota al tenant a mano porque el global scope de Eloquent no aplica al
            // validador.
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/%]+$/',
                Rule::unique('units', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'name' => ['required', 'string', 'max:60'],

            'dimension' => ['required', new Enum(UnitDimension::class)],

            // El factor multiplica TODAS las cantidades expresadas en esta unidad, así que un cero o
            // un negativo contaminarían el costeo completo sin producir ningún error visible. Hay
            // además un CHECK en la tabla: esta regla da el mensaje, el CHECK da la garantía.
            'factor_to_base' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe una unidad con ese código.',
            'code.regex' => 'El código sólo admite letras, números, diagonal y porcentaje.',
            'factor_to_base.gt' => 'El factor de conversión tiene que ser mayor que cero.',
            'factor_to_base.decimal' => 'El factor admite hasta ocho decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'el código',
            'name' => 'el nombre',
            'dimension' => 'la magnitud',
            'factor_to_base' => 'el factor de conversión',
        ];
    }
}
