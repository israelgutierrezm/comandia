<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la factura del proveedor.
 *
 * @property string $quantity
 * @property string $quantity_in_base_unit
 * @property string $unit_price
 * @property string $tax_rate
 * @property string $line_subtotal
 * @property string $line_total
 */
final class PurchaseReceiptLine extends DomainModel
{
    protected $table = 'purchase_receipt_lines';

    protected $fillable = [
        'purchase_receipt_id',
        'article_id',
        'presentation_id',
        'quantity',
        'quantity_in_base_unit',
        'unit_price',
        'tax_rate',
        'line_subtotal',
        'line_tax',
        'line_total',
        'lot_code',
        'expires_at',
        'lot_id',
        'movement_id',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_date'];
    }

    /** @return BelongsTo<PurchaseReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<ArticlePurchasePresentation, $this> */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(ArticlePurchasePresentation::class, 'presentation_id');
    }

    /**
     * `lot_id` y `movement_id` NO tienen relación de Eloquent, y no es un olvido.
     *
     * Apuntan a tablas de `Inventory`, y declarar la relación obligaría a este modelo a importar sus clases — con eso,
     * `Purchasing` referenciaría a `Inventory` mientras el oyente de `Inventory` referencia a `Purchasing`, y el grafo
     * de módulos quedaría con un **ciclo**. Un ciclo entre módulos de dominio es lo que ADR-001 existe para impedir, y
     * el candado de fronteras lo rechazaría.
     *
     * Las FK sí se quedan: la dependencia de DATOS es inevitable y deseable —garantizan que el enlace apunte a algo
     * real— igual que `recipe_lines.component_article_id` hacia `articles`. Lo que se evita es la de CÓDIGO.
     *
     * Consecuencia que hay que aceptar: la recepción no puede mostrar el lote ni el movimiento resueltos por relación.
     * Muestra el lote **como se capturó** —que es lo que la factura decía— y `wasApplied()` contesta si el renglón
     * llegó al kardex, que es la pregunta que de verdad importa: una línea con cantidad y sin movimiento es una
     * confirmación que se interrumpió.
     */
    public function wasApplied(): bool
    {
        return $this->movement_id !== null;
    }

    /**
     * El costo por UNIDAD BASE con el que este renglón entra al inventario.
     *
     * Es la cifra que va al kardex y al historial de costos, y **no es `unit_price`**: ése es por unidad de captura —la
     * caja— y hay que repartirlo entre las 36 000 unidades base que trae. Confundir los dos daría un costo por gramo
     * igual al precio de la caja, y de ahí un valor de inventario mil veces inflado.
     *
     * `$vatIsCreditable` decide si el impuesto forma parte: acreditable, no —se recupera contra el IVA cobrado—; sin
     * acreditar, sí, porque es dinero que no vuelve. El criterio lo pasa quien confirma y queda congelado en la
     * recepción, para que el costo de una recepción vieja siga siendo explicable si el ajuste cambia.
     *
     * @return numeric-string
     */
    public function costPerBaseUnit(bool $vatIsCreditable): string
    {
        $amount = $vatIsCreditable ? $this->line_subtotal : $this->line_total;

        return Decimal::divide($amount, $this->quantity_in_base_unit, 4);
    }
}
