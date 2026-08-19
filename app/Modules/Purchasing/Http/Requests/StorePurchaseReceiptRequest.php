<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Captura de una recepción de compra (D26, §3.2).
 *
 * ## Lo que se captura es la FACTURA, tal como está escrita
 *
 * Cantidad en la unidad de la presentación («3 cajas»), precio **sin IVA** por esa unidad, y la tasa del renglón. Los
 * importes los calcula el servidor: aceptarlos del cliente permitiría una recepción cuyo total no cuadra con sus
 * renglones, y ese documento es imposible de conciliar con la factura — que es lo único que la recepción existe para
 * hacer.
 *
 * ## `tax_rate` por línea, con la del negocio por omisión
 *
 * Una factura mezcla tasas: alimentos preparados al 16 % y despensa al 0 %, en el mismo papel. Por línea cuesta una
 * columna y evita del lado de las compras el problema que D150 dejó abierto del lado de las ventas — aquí la factura ya
 * dice la tasa de cada renglón, así que no hay nada que adivinar.
 *
 * ## El lote se captura como TEXTO
 *
 * Como viene escrito en la caja. El `article_lots` se crea al **confirmar**, no aquí: un borrador que ya hubiera creado
 * lotes dejaría lotes huérfanos si nunca se confirma, y un lote huérfano aparece en el selector de FEFO como si tuviera
 * mercancía por surtir.
 */
final class StorePurchaseReceiptRequest extends FormRequest
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
            'supplier_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('suppliers', 'ulid')->where('tenant_id', $tenantId),
            ],

            'warehouse_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('warehouses', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // El almacén de tránsito lo escriben sólo las transferencias (D190).
                    ->whereNot('kind', 'transit'),
            ],

            // La fecha en que llegó la mercancía. Se admite pasada porque la factura se captura tarde, y el kardex tiene
            // que decir cuándo entró de verdad.
            'received_at' => ['required', 'date', 'before_or_equal:today'],

            // Única por proveedor, y con mensaje propio. El índice único de la tabla la rechazaría igual, pero como un
            // 500 — y capturar dos veces la misma factura es el error de captura MÁS CARO de todos: duplica existencia,
            // duplica costo y descuadra el inventario contra la realidad sin que nada avise. Quien lo intenta merece
            // saber que esa factura ya está capturada, no un error del servidor.
            'supplier_document_number' => [
                'nullable', 'string', 'max:60',
                Rule::unique('purchase_receipts', 'supplier_document_number')
                    ->where('tenant_id', $tenantId)
                    ->where('supplier_id', $this->supplierId()),
            ],

            'lines' => ['required', 'array', 'min:1', 'max:200'],

            'lines.*.article_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('articles', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // Sólo lo que tiene existencia: recibir algo que no se inventaría no puede aumentar ningún saldo.
                    ->where('is_inventoriable', true),
            ],

            'lines.*.presentation_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('article_purchase_presentations', 'ulid')->where('tenant_id', $tenantId),
            ],

            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            // Precio SIN IVA por unidad de captura. Se admite CERO: una muestra del proveedor llega en la factura a
            // cero, y es un hecho legítimo — a diferencia del precio de proveedor, donde un cero envenenaría la
            // comparación (D203).
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'max:99999999.9999', 'decimal:0,4'],

            'lines.*.tax_rate' => ['sometimes', 'required', 'numeric', 'gte:0', 'lte:100', 'decimal:0,2'],

            'lines.*.lot_code' => ['nullable', 'string', 'max:60'],
            'lines.*.expires_at' => ['nullable', 'date'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * El id interno del proveedor que viene en la petición, para el único compuesto.
     *
     * Devuelve 0 cuando el ULID no corresponde a nada: entonces la regla `exists` ya falló y este valor no se
     * usa para nada — pero devolver `null` haría que la comparación fuera `supplier_id IS NULL` y el único
     * dejaría pasar cualquier cosa.
     */
    private function supplierId(): int
    {
        return (int) Supplier::query()
            ->where('ulid', $this->string('supplier_ulid')->toString())
            ->value('id');
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

                /** @var array<int, array<string, mixed>> $lines */
                $lines = $this->input('lines');

                $seen = [];

                foreach ($lines as $index => $line) {
                    $articleUlid = (string) $line['article_ulid'];
                    $presentationUlid = $line['presentation_ulid'] ?? null;
                    $lotCode = $line['lot_code'] ?? null;

                    // El mismo renglón exacto dos veces es el dedazo que duplica existencia. El índice único lo
                    // rechazaría igual; aquí produce un mensaje que dice cuál renglón sobra.
                    $key = $articleUlid.'|'.($presentationUlid ?? '').'|'.($lotCode ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "lines.{$index}.article_ulid",
                            'Este renglón ya viene capturado. Junta las cantidades en uno: capturarlo dos veces '
                            .'duplicaría la existencia que entra.'
                        );

                        continue;
                    }

                    $seen[$key] = true;

                    $this->validatePresentation($validator, $index, $articleUlid, $presentationUlid);
                    $this->validateLot($validator, $index, $articleUlid, $lotCode);
                }
            },
        ];
    }

    private function validatePresentation(
        Validator $validator,
        int $index,
        string $articleUlid,
        ?string $presentationUlid,
    ): void {
        if ($presentationUlid === null) {
            return;
        }

        $belongs = ArticlePurchasePresentation::query()
            ->where('ulid', $presentationUlid)
            ->whereHas('article', fn ($query) => $query->where('ulid', $articleUlid))
            ->exists();

        if (! $belongs) {
            $validator->errors()->add(
                "lines.{$index}.presentation_ulid",
                'Esa presentación no es de este artículo. Con la presentación equivocada, la conversión a unidad base '
                .'daría una cantidad que no corresponde a nada — y ésa es la que entra al inventario.'
            );
        }
    }

    /**
     * Capturar un lote en un artículo que no los lleva no serviría de nada: el sistema no lo usaría al surtir.
     *
     * Se rechaza en lugar de ignorarlo en silencio, porque quien lo capturó cree haber registrado la caducidad — y el
     * día que la mercancía se pase, nadie va a entender por qué el sistema no avisó.
     */
    private function validateLot(Validator $validator, int $index, string $articleUlid, ?string $lotCode): void
    {
        if ($lotCode === null) {
            return;
        }

        $tracksLots = Article::query()->where('ulid', $articleUlid)->value('tracks_lots');

        if (! $tracksLots) {
            $validator->errors()->add(
                "lines.{$index}.lot_code",
                'Este artículo no lleva control de lotes, así que el lote capturado no se usaría al surtir. Actívale '
                .'el control de lotes en el catálogo si lo necesitas.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.article_ulid.exists' => 'Alguno de los artículos no existe o no se inventaría.',
            'lines.*.quantity.gt' => 'La cantidad recibida tiene que ser mayor que cero.',
            'lines.*.tax_rate.lte' => 'La tasa de impuesto no puede pasar del 100 %.',
            'supplier_document_number.unique' => 'Esa factura de ese proveedor ya está capturada. Capturarla dos veces '
                .'duplicaría la existencia y el costo, y el inventario dejaría de cuadrar con la realidad sin que nada '
                .'avise. Busca la recepción que ya existe.',
            'received_at.before_or_equal' => 'La fecha de recepción no puede estar en el futuro.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_ulid' => 'el proveedor',
            'warehouse_ulid' => 'el almacén',
            'received_at' => 'la fecha de recepción',
            'supplier_document_number' => 'el folio de la factura',
            'lines' => 'los renglones',
            'lines.*.article_ulid' => 'el artículo',
            'lines.*.presentation_ulid' => 'la presentación',
            'lines.*.quantity' => 'la cantidad',
            'lines.*.unit_price' => 'el precio',
            'lines.*.tax_rate' => 'la tasa de impuesto',
            'lines.*.lot_code' => 'el lote',
            'lines.*.expires_at' => 'la caducidad',
            'notes' => 'las notas',
        ];
    }
}
