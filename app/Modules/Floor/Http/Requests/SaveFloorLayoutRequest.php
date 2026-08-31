<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El salón entero, en una sola escritura (Iteración 5, §1.2).
 *
 * ## Por qué por lote y no una mesa a la vez
 *
 * Arrastrar doce mesas y guardar produciría doce escrituras independientes. Si la quinta falla, el plano queda a
 * medias —mitad nuevo, mitad viejo— y nadie sabe cuál es cuál. Un salón a medias no es un salón: las mesas se mueven
 * unas respecto de otras, así que la mitad guardada puede describir una distribución que no existe ni existió nunca.
 *
 * ## Los límites tienen razón, no son cifras redondas
 *
 * `max:500` mesas: es un salón enorme (un estadio pequeño) y a la vez impide que una petición mal formada intente
 * escribir el catálogo completo del negocio en una transacción.
 *
 * Las coordenadas son **centímetros** (ADR-003, fijado en la Iteración 5). `min:1` de ancho y alto porque una mesa de
 * cero centímetros no se puede ni tocar en la pantalla, y `max:5000` porque cincuenta metros ya no es una mesa: es un
 * error de captura, y sin tope quedaría una figura que tapa el plano entero y no se puede agarrar para arreglarla.
 */
final class SaveFloorLayoutRequest extends FormRequest
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
            // La versión que el editor leyó. Sin ella no hay forma de saber si alguien más guardó mientras tanto, y
            // dos gerentes editando se pisarían sin enterarse.
            'version' => ['required', 'integer', 'min:1'],

            'canvas' => ['sometimes', 'array'],
            'canvas.width' => ['required_with:canvas', 'numeric', 'min:100', 'max:99999.99', 'decimal:0,2'],
            'canvas.height' => ['required_with:canvas', 'numeric', 'min:100', 'max:99999.99', 'decimal:0,2'],

            // Puede venir VACÍO: un plano recién creado no tiene mesas y guardar el lienzo es un guardado legítimo.
            'tables' => ['present', 'array', 'max:500'],
            'tables.*.ulid' => ['required', 'string', 'size:26'],
            'tables.*.zone_ulid' => ['sometimes', 'string', 'size:26'],

            'tables.*.x' => ['required', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'tables.*.y' => ['required', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'tables.*.width' => ['required', 'numeric', 'min:1', 'max:5000', 'decimal:0,2'],
            'tables.*.height' => ['required', 'numeric', 'min:1', 'max:5000', 'decimal:0,2'],

            // Una vuelta completa y nada más: 360 y 0 son la misma mesa, y permitir 720 dejaría dos valores para el
            // mismo dibujo que no se pueden comparar.
            'tables.*.rotation' => ['required', 'numeric', 'min:0', 'max:359.99', 'decimal:0,2'],

            'tables.*.shape' => ['required', 'string', 'in:rectangle,circle'],

            // Elementos decorativos (ADR-011): al guardar el layout viaja SÓLO su geometría —el tipo y el texto se
            // fijan por su propio CRUD—. Opcional: un plano puede no tener ninguno.
            'elements' => ['sometimes', 'array', 'max:500'],
            'elements.*.ulid' => ['required', 'string', 'size:26'],
            'elements.*.x' => ['required', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'elements.*.y' => ['required', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'elements.*.width' => ['required', 'numeric', 'min:1', 'max:5000', 'decimal:0,2'],
            'elements.*.height' => ['required', 'numeric', 'min:1', 'max:5000', 'decimal:0,2'],
            'elements.*.rotation' => ['required', 'numeric', 'min:0', 'max:359.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Falta la versión del plano: sin ella no se puede saber si alguien más lo movió.',
            'tables.max' => 'Un plano admite hasta 500 mesas.',
            'tables.*.width.max' => 'Una mesa de más de 50 metros es un error de captura.',
            'tables.*.height.max' => 'Una mesa de más de 50 metros es un error de captura.',
            'tables.*.rotation.max' => 'La rotación va de 0 a 359.99 grados.',
            'canvas.width.min' => 'El salón mide al menos un metro de ancho.',
            'canvas.height.min' => 'El salón mide al menos un metro de alto.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'version' => 'la versión',
            'canvas.width' => 'el ancho del salón',
            'canvas.height' => 'el alto del salón',
            'tables' => 'las mesas',
        ];
    }
}
