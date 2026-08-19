<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Purchasing\Domain\Enums\SupplierPriceSource;
use App\Modules\Purchasing\Domain\Exceptions\SupplierPriceInvariantException;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Purchasing\Infrastructure\Models\SupplierPrice;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Registra una observación de precio de proveedor (D26).
 *
 * **La única puerta de entrada al historial de precios de proveedor**, por lo mismo que `RecordStockMovement` es la
 * única al kardex: aquí se normaliza a unidad base, y un segundo camino que escribiera la tabla directamente dejaría
 * observaciones sin normalizar que después se comparan como si lo estuvieran — dando por más caro al proveedor que
 * vende en cajas grandes.
 *
 * ## Normalizar es lo que hace posible comparar
 *
 * La factura dice «3 cajas de 12 kg, $480 la caja». Comparar eso con «$42 el kilo» de otro proveedor exige llevar los
 * dos al mismo terreno: `unit_price` es **siempre por unidad base**. Lo capturado se conserva aparte
 * —`observed_quantity` y `observed_price`— para poder explicar de dónde salió el número normalizado, que es lo primero
 * que alguien pide cuando la comparación no le cuadra.
 *
 * ## Dos formas de capturar, según qué se sabe
 *
 *   - **Con presentación:** «la caja a 480». La cantidad en unidad base la sabe la presentación (`quantity_in_base_unit`,
 *     inmutable desde D147), así que se divide por ella. Es el caso de una factura.
 *   - **Sin presentación:** «el kilo a 42». Se toma tal cual, ya está en unidad base.
 *
 * No se admite «3 cajas por 1440» sin decir el precio unitario: la ambigüedad entre precio total y precio por pieza es
 * el error de captura más común de una factura, y resolverla adivinando produciría un historial con precios treinta
 * veces mal que nadie sospecharía.
 */
final class RecordSupplierPrice
{
    public function __construct(private readonly ContextHolder $context) {}

    /**
     * Una observación con presentación de compra: el precio es **por presentación**.
     *
     * @param  numeric-string  $pricePerPresentation
     *
     * @throws SupplierPriceInvariantException
     */
    public function forPresentation(
        Supplier $supplier,
        ArticlePurchasePresentation $presentation,
        string $pricePerPresentation,
        SupplierPriceSource $source,
        ?CarbonImmutable $observedAt = null,
        string $currency = 'MXN',
        ?string $notes = null,
        ?int $purchaseReceiptId = null,
    ): SupplierPrice {
        /** @var numeric-string $inBase */
        $inBase = $presentation->quantity_in_base_unit;

        if (bccomp($inBase, '0', 4) !== 1) {
            // No debería ocurrir: la presentación valida su cantidad al guardarse. Si ocurre, dividir daría una
            // división por cero o un precio absurdo, y un precio absurdo en el historial es peor que un error.
            throw SupplierPriceInvariantException::presentationWithoutQuantity($presentation->name ?? '');
        }

        return $this->write(
            supplier: $supplier,
            article: $presentation->article,
            presentationId: $presentation->id,
            unitPrice: Decimal::divide($pricePerPresentation, $inBase, 4),
            observedQuantity: $inBase,
            observedPrice: $pricePerPresentation,
            source: $source,
            observedAt: $observedAt,
            currency: $currency,
            notes: $notes,
            purchaseReceiptId: $purchaseReceiptId,
        );
    }

    /**
     * Una observación por unidad base: el precio ya viene normalizado.
     *
     * @param  numeric-string  $unitPrice
     *
     * @throws SupplierPriceInvariantException
     */
    public function forBaseUnit(
        Supplier $supplier,
        Article $article,
        string $unitPrice,
        SupplierPriceSource $source,
        ?CarbonImmutable $observedAt = null,
        string $currency = 'MXN',
        ?string $notes = null,
        ?int $purchaseReceiptId = null,
    ): SupplierPrice {
        return $this->write(
            supplier: $supplier,
            article: $article,
            presentationId: null,
            unitPrice: Decimal::round($unitPrice, 4),
            observedQuantity: null,
            observedPrice: null,
            source: $source,
            observedAt: $observedAt,
            currency: $currency,
            notes: $notes,
            purchaseReceiptId: $purchaseReceiptId,
        );
    }

    /**
     * @param  numeric-string  $unitPrice
     * @param  numeric-string|null  $observedQuantity
     * @param  numeric-string|null  $observedPrice
     *
     * @throws SupplierPriceInvariantException
     */
    private function write(
        Supplier $supplier,
        Article $article,
        ?int $presentationId,
        string $unitPrice,
        ?string $observedQuantity,
        ?string $observedPrice,
        SupplierPriceSource $source,
        ?CarbonImmutable $observedAt,
        string $currency,
        ?string $notes,
        ?int $purchaseReceiptId,
    ): SupplierPrice {
        if (! $supplier->isActive()) {
            // Un proveedor dado de baja sigue existiendo —su historial lo cita— pero no se le capturan precios
            // nuevos: un precio nuevo de alguien a quien ya no se le compra sólo puede ser un error de selección.
            throw SupplierPriceInvariantException::inactiveSupplier($supplier->displayName());
        }

        if (bccomp($unitPrice, '0', 4) !== 1) {
            // Cero no es un precio, es la ausencia de uno. Y admitirlo envenenaría la comparación: el proveedor que
            // «regala» saldría siempre como el más barato.
            throw SupplierPriceInvariantException::nonPositivePrice();
        }

        // `refresh()` al final, y es la CUARTA vez que esta familia de defectos aparece (D134, D149, paso 4): sin
        // releer, los decimales vuelven tal como se mandaron —`480` en lugar de `480.0000`— porque Eloquent devuelve
        // el atributo asignado y no el que la base guardó. En un precio, la diferencia se nota en el cliente que lo
        // formatea.
        $price = SupplierPrice::create([
            'supplier_id' => $supplier->id,
            'article_id' => $article->id,
            'presentation_id' => $presentationId,
            'unit_price' => $unitPrice,
            'observed_quantity' => $observedQuantity,
            'observed_price' => $observedPrice,
            'currency' => mb_strtoupper($currency),

            // Por omisión hoy. Se admite una fecha pasada porque una factura se captura tarde y el historial tiene que
            // decir cuándo se observó el precio, no cuándo se teclearon los datos.
            'observed_at' => $observedAt ?? CarbonImmutable::now(),

            'source' => $source,
            'purchase_receipt_id' => $purchaseReceiptId,

            // `null` cuando lo escribe el sistema al confirmar una recepción: no se inventa un actor, igual que en el
            // kardex.
            'registered_by_membership_id' => $this->context->getOrNull()?->membership?->id,

            'notes' => $notes,
        ]);

        return $price->refresh();
    }
}
