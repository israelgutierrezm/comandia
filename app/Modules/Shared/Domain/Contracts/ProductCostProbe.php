<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * «¿Cuánto cuesta este artículo AHORA?»
 *
 * ## Por qué es una sonda del kernel y no una dependencia directa
 *
 * A diferencia de las otras tres sondas —`LiveServiceProbe`, `CashSessionProbe`, `ConsumptionHistoryProvider`, que
 * invierten la dependencia para romper un **ciclo**—, aquí no hay ciclo: `Costing` no depende de `Pos`, así que `Pos`
 * podría depender de `Costing` directamente. La razón de la sonda es otra: **el POS nunca se bloquea** (§6). Al capturar
 * una venta, el POS congela el costo del momento (D322) para que el reporte de margen sea fiel; pero si el costo no se
 * puede resolver —el módulo no está, un fallo—, cobrar debe seguir. El null-object devuelve `"0"`: el margen de esa venta
 * quedará sin costo, que es un dato incompleto, no una venta rota. Preferir un cero honesto a un 500 en la caja.
 *
 * `Costing` implementa este contrato leyendo su proyección de costo vigente; `Pos` lo consume al capturar. Ninguno se
 * nombra al otro: los dos conocen el kernel.
 */
interface ProductCostProbe
{
    /**
     * El costo unitario vigente del artículo, como DECIMAL en cadena (escala de costos, 12,4), o `"0"` si el artículo
     * nunca tuvo costo (nunca se compró ni se costeó) o si el costo no se puede resolver.
     *
     * Recibe el id interno del artículo —quien captura ya tiene el artículo resuelto— y devuelve un primitivo: el POS
     * jamás toca un modelo de `Costing`.
     */
    public function currentUnitCost(int $articleId): string;
}
