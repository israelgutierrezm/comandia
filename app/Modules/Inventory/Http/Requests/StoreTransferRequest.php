<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Solicitud de una transferencia (D25, §6.2).
 *
 * Origen y destino distintos se comprueba aquí **y** con un CHECK en la base. No es redundancia inútil: aquí produce
 * un mensaje que la persona entiende, y allá es la garantía de que no entre por otro camino.
 */
final class StoreTransferRequest extends FormRequest
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

        $warehouseExists = Rule::exists('warehouses', 'ulid')
            ->where('tenant_id', $tenantId)
            // El almacén de tránsito NO es un origen ni un destino elegible: lo escriben sólo las transferencias.
            // Se excluye aquí además del corte del servicio, para que el error sea de captura y no de dominio.
            ->whereNot('kind', 'transit');

        return [
            'origin_warehouse_ulid' => ['required', 'string', 'size:26', $warehouseExists],
            'destination_warehouse_ulid' => [
                'required', 'string', 'size:26', 'different:origin_warehouse_ulid', $warehouseExists,
            ],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
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

            // Mayor que cero: pedir cero es no pedir. A diferencia de lo contado en un conteo físico, aquí un cero
            // no es un hecho — es un renglón que no debería estar en la solicitud.
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            'notes' => ['nullable', 'string', 'max:300'],
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
                    $key = $line['article_ulid'].'|'.($lotUlid ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "lines.{$index}.article_ulid",
                            'Este artículo y lote ya vienen en otro renglón. Junta las cantidades en uno: dos '
                            .'renglones del mismo artículo se enviarían los dos.'
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
                        $validator->errors()->add("lines.{$index}.lot_ulid", 'Ese lote no es de este artículo.');
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
            'destination_warehouse_ulid.different' => 'El destino tiene que ser distinto del origen.',
            'origin_warehouse_ulid.exists' => 'Ese almacén de origen no existe o no admite transferencias.',
            'destination_warehouse_ulid.exists' => 'Ese almacén de destino no existe o no admite transferencias.',
            'lines.*.quantity.gt' => 'La cantidad solicitada tiene que ser mayor que cero.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'origin_warehouse_ulid' => 'el almacén de origen',
            'destination_warehouse_ulid' => 'el almacén de destino',
            'lines' => 'los renglones',
            'lines.*.article_ulid' => 'el artículo',
            'lines.*.lot_ulid' => 'el lote',
            'lines.*.quantity' => 'la cantidad',
            'notes' => 'las notas',
        ];
    }
}
