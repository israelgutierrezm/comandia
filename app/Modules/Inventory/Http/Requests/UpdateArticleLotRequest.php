<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Enums\LotStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de un lote.
 *
 * ## Qué se puede cambiar, y qué no
 *
 * El **artículo** y el **código** son inmutables: los movimientos de inventario ya los citan, y reasignarlos
 * reinterpretaría existencias que ya se movieron. Es la misma regla que la unidad base de un artículo (D96) y la
 * cantidad de una presentación (D147), y el modelo la vuelve a imponer.
 *
 * La **caducidad** sí se corrige: es un dato del envase que se pudo teclear mal, y corregirlo no reinterpreta
 * ningún movimiento pasado — sólo cambia el orden en que saldrá lo que queda, que es precisamente lo que se
 * quiere al descubrir el error.
 */
final class UpdateArticleLotRequest extends FormRequest
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
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'required', Rule::enum(LotStatus::class)],

            // `prohibited` y no ignorados: quien los manda cree que va a cambiar algo, y un cambio que se
            // acepta y no ocurre es peor que un rechazo.
            'code' => ['prohibited'],
            'article_ulid' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'El código de un lote no se puede cambiar: los movimientos de inventario ya lo '.
                'citan. Crea otro lote.',
            'article_ulid.prohibited' => 'Un lote no cambia de artículo: reinterpretaría las existencias que ya '.
                'se movieron con él.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'expires_at' => 'la caducidad',
            'status' => 'el estado',
        ];
    }
}
