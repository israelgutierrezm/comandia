<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Purchasing\Domain\Enums\SupplierPriceSource;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Captura de una observación de precio de proveedor (D26).
 *
 * ## Dos formas de capturar, y hay que elegir una
 *
 *   - **Con `presentation_ulid`:** `price` es el precio **de la presentación** («la caja de 12 kg a 480»). El servicio
 *     divide por la cantidad en unidad base para normalizar.
 *   - **Sin ella:** `price` ya es **por unidad base** («el kilo a 42»).
 *
 * La ambigüedad entre las dos es el error de captura más común de una factura, y resolverla adivinando produciría un
 * historial con precios doce veces mal que nadie sospecharía. Así que la presencia de la presentación es lo que decide,
 * y el mensaje de error lo dice.
 *
 * ## `source` no admite `receipt`
 *
 * Ése lo escribe el sistema al confirmar una recepción (paso 9). Permitir capturarlo a mano dejaría que alguien
 * marcara como «precio pagado» algo que nunca se pagó, y la comparación perdería su distinción más útil: un hecho
 * frente a una promesa.
 */
final class StoreSupplierPriceRequest extends FormRequest
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
            'article_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('articles', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // Sólo lo que se compra: un artículo que no es insumo ni inventariable no tiene precio de
                    // proveedor que registrar.
                    ->where(fn ($query) => $query->where('is_supply', true)->orWhere('is_inventoriable', true)),
            ],

            'presentation_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('article_purchase_presentations', 'ulid')->where('tenant_id', $tenantId),
            ],

            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            // ISO 4217. Sin conversión en el sistema: la moneda existe para que el dato sea cierto, y la comparación
            // agrupa por ella en lugar de mezclar.
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],

            'observed_at' => ['nullable', 'date', 'before_or_equal:today'],

            'source' => [
                'sometimes', 'required',
                Rule::in([SupplierPriceSource::Quote->value, SupplierPriceSource::Manual->value]),
            ],

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
                if ($validator->errors()->isNotEmpty() || ! $this->filled('presentation_ulid')) {
                    return;
                }

                $belongs = ArticlePurchasePresentation::query()
                    ->where('ulid', $this->string('presentation_ulid')->toString())
                    ->whereHas('article', fn ($query) => $query->where('ulid', $this->string('article_ulid')->toString()))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add(
                        'presentation_ulid',
                        'Esa presentación no es de este artículo. Normalizaría el precio con la cantidad equivocada, y '
                        .'el historial quedaría con un precio por unidad que no corresponde a nada.'
                    );
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
            'article_ulid.exists' => 'Ese artículo no existe o no es algo que se compre.',
            'price.gt' => 'El precio tiene que ser mayor que cero. Un cero no es un precio bajo: es la ausencia de '
                .'precio, y en la comparación saldría siempre como el más barato.',
            'source.in' => 'Un precio de recepción lo escribe el sistema al confirmar la recepción. A mano sólo se '
                .'capturan cotizaciones y precios de lista.',
            'observed_at.before_or_equal' => 'La fecha de observación no puede estar en el futuro.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'article_ulid' => 'el artículo',
            'presentation_ulid' => 'la presentación',
            'price' => 'el precio',
            'currency' => 'la moneda',
            'observed_at' => 'la fecha',
            'source' => 'el origen',
            'notes' => 'las notas',
        ];
    }
}
