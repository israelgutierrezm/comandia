<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Las cantidades de un envío o de una recepción.
 *
 * Una sola clase para los dos pasos porque la forma es idéntica —una lista de renglones con su cantidad— y los
 * límites que los distinguen no son de captura: «no más de lo pedido» y «no más de lo enviado» dependen de lo que la
 * línea ya tiene escrito, y comprobarlos aquí exigiría leerlas todas y dejaría una ventana entre la validación y el
 * efecto. El servicio los comprueba con el documento bloqueado.
 *
 * Los renglones se identifican por ULID de ARTÍCULO y lote, no por id de línea: la API no expone llaves internas
 * (§7), y quien captura tiene delante la hoja de embarque con nombres de artículo, no números de renglón.
 *
 * Se admite CERO, y hace falta: «de esto no salió nada» y «de esto no llegó nada» son respuestas legítimas. Un
 * renglón omitido se trata como cero de todos modos, pero mandarlo en cero deja constancia de que alguien lo miró.
 */
final class TransferQuantitiesRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.article_ulid' => ['required', 'string', 'size:26'],
            'lines.*.lot_ulid' => ['nullable', 'string', 'size:26'],
            'lines.*.quantity' => ['required', 'numeric', 'gte:0', 'max:99999999.9999', 'decimal:0,4'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Manda al menos un renglón con su cantidad.',
            'lines.*.quantity.gte' => 'La cantidad no puede ser negativa.',
            'lines.*.quantity.decimal' => 'La cantidad admite hasta cuatro decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lines' => 'los renglones',
            'lines.*.article_ulid' => 'el artículo',
            'lines.*.lot_ulid' => 'el lote',
            'lines.*.quantity' => 'la cantidad',
        ];
    }
}
