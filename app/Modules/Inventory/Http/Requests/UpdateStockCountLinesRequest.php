<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Captura masiva de cantidades contadas.
 *
 * ## `counted_quantity` admite `null`, y hace falta
 *
 * `null` significa «esta línea no se contó» y sirve para **deshacer** una captura equivocada antes de cerrar. Sin
 * él, un dedazo sólo se podría corregir por otro número, y no habría forma de volver a «no contado» — que es
 * distinto de cero y produce un ajuste distinto (ninguno, frente a uno que vacía la existencia).
 *
 * ## Se admite cero, y también hace falta
 *
 * «Se contó y no había» es el resultado más común de un conteo cíclico bien hecho, y es el que genera el ajuste que
 * corrige el saldo fantasma. Un Form Request que exigiera `gt:0` haría imposible registrar precisamente eso.
 *
 * ## Sin cantidades negativas
 *
 * A diferencia del saldo esperado, que sí puede ser negativo (§6.2). Lo esperado es una cuenta que puede haber
 * quedado en negativo; lo contado es lo que hay en el estante, y en un estante no hay menos que nada. Un negativo
 * aquí es siempre un error de captura.
 */
final class UpdateStockCountLinesRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],

            'lines.*.article_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('articles', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_inventoriable', true),
            ],

            'lines.*.lot_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('article_lots', 'ulid')->where('tenant_id', $tenantId),
            ],

            // `present` y `nullable`: la clave tiene que venir —para que «no contado» sea explícito y no un
            // olvido— y su valor puede ser nulo.
            'lines.*.counted_quantity' => ['present', 'nullable', 'numeric', 'gte:0', 'max:99999999.9999', 'decimal:0,4'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var array<int, array{article_ulid: string, lot_ulid?: string|null}> $lines */
                $lines = $this->input('lines');

                $seen = [];

                foreach ($lines as $index => $line) {
                    $lotUlid = $line['lot_ulid'] ?? null;

                    // Dos renglones del mismo artículo y lote en la misma hoja son dos conteos de la misma cosa, y
                    // el segundo pisaría al primero en silencio. Mejor rechazar la hoja: quien la capturó tiene
                    // que decidir cuál de los dos números es el bueno.
                    $key = $line['article_ulid'].'|'.($lotUlid ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "lines.{$index}.article_ulid",
                            'Este artículo y lote aparecen dos veces en la hoja. Deja un solo renglón por '.
                            'artículo: dos números para la misma cosa no se pueden conciliar solos.'
                        );

                        continue;
                    }

                    $seen[$key] = true;

                    if ($lotUlid === null) {
                        continue;
                    }

                    $belongs = ArticleLot::query()
                        ->where('ulid', $lotUlid)
                        ->whereHas('article', fn ($query) => $query->where('ulid', $line['article_ulid']))
                        ->exists();

                    if (! $belongs) {
                        $validator->errors()->add(
                            "lines.{$index}.lot_ulid",
                            'Ese lote no es de este artículo.'
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Manda al menos un renglón contado.',
            'lines.max' => 'Manda la hoja en tandas de 500 renglones o menos.',
            'lines.*.counted_quantity.present' => 'Cada renglón tiene que traer la cantidad contada, aunque sea '.
                'nula: así «no lo conté» queda dicho y no se confunde con un olvido.',
            'lines.*.counted_quantity.gte' => 'La cantidad contada no puede ser negativa: en un estante no hay '.
                'menos que nada.',
            'lines.*.counted_quantity.decimal' => 'La cantidad admite hasta cuatro decimales.',
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
            'lines.*.counted_quantity' => 'la cantidad contada',
        ];
    }
}
