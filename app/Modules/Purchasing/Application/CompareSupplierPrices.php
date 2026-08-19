<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Purchasing\Infrastructure\Models\SupplierPrice;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * La comparación entre proveedores y la detección de subidas (§6.2, D26).
 *
 * Es la razón por la que `supplier_prices` es un historial y no un precio vigente. Contesta las dos preguntas que el
 * negocio se hace antes de comprar, y ninguna de las dos se puede contestar con una sola fila por proveedor:
 *
 *   1. **¿Quién me lo vende más barato?** — exige el último precio de cada proveedor.
 *   2. **¿Me subió el precio?** — exige el último **y el anterior** del mismo proveedor.
 *
 * ## Se calcula aquí y no en el cliente
 *
 * La variación es una resta y una división, y es exactamente donde se cuela el error: dividir por el precio nuevo en
 * lugar del viejo, o presentar la diferencia como porcentaje del total. El frontend previsualiza, el backend decide
 * (regla 5 del Definition of Done).
 *
 * ## Se agrupa POR MONEDA, y eso no es un detalle
 *
 * No hay tipo de cambio en el sistema y no se va a inventar uno. Comparar un precio en dólares con uno en pesos daría
 * al proveedor importado como veinte veces más barato, así que las monedas **no se mezclan**: cada moneda es su propia
 * comparación. Dos precios que no se pueden comparar bien es mejor que dos precios comparados mal — el mismo argumento
 * que el costeo usa cuando falta un costo.
 */
final class CompareSupplierPrices
{
    /**
     * Para un artículo: qué cobra cada proveedor y cómo cambió.
     *
     * @return list<array<string, mixed>> un renglón por (proveedor, moneda), del más barato al más caro
     */
    public function forArticle(Article $article): array
    {
        $observations = SupplierPrice::query()
            ->forArticle($article->id)
            ->with(['supplier', 'presentation'])
            ->mostRecentFirst()
            ->get();

        // Agrupado por proveedor Y moneda: el mismo proveedor puede cotizar en dos monedas —el importado en dólares y
        // el nacional en pesos— y mezclarlos daría una «subida» que sólo es un cambio de divisa.
        $groups = [];

        foreach ($observations as $observation) {
            $key = $observation->supplier_id.'|'.$observation->currency;
            $groups[$key][] = $observation;
        }

        $rows = [];

        foreach ($groups as $group) {
            // Ya vienen del más reciente al más viejo, así que el primero es el actual y el segundo el anterior.
            $latest = $group[0];
            $previous = $group[1] ?? null;

            $rows[] = [
                'supplier' => [
                    'ulid' => $latest->supplier->ulid,
                    'code' => $latest->supplier->code,
                    'name' => $latest->supplier->displayName(),
                    'is_active' => $latest->supplier->isActive(),
                ],

                'currency' => $latest->currency,

                'latest' => $this->observation($latest),
                'previous' => $previous === null ? null : $this->observation($previous),

                // La detección de subidas. `null` cuando no hay con qué comparar: una sola observación no es una
                // tendencia, y presentar «0 %» diría que el precio se mantuvo, que es una afirmación que no se puede
                // hacer.
                'change' => $previous === null ? null : $this->change($previous->unit_price, $latest->unit_price),

                'observations' => count($group),
            ];
        }

        // Del más barato al más caro DENTRO de cada moneda. El orden entre monedas es alfabético y no significa nada
        // —no se pueden comparar— y ordenarlas juntas por precio insinuaría que sí.
        usort($rows, function (array $a, array $b): int {
            if ($a['currency'] !== $b['currency']) {
                return strcmp($a['currency'], $b['currency']);
            }

            return bccomp($a['latest']['unit_price'], $b['latest']['unit_price'], 4);
        });

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(SupplierPrice $price): array
    {
        return [
            'ulid' => $price->ulid,
            'unit_price' => $price->unit_price,
            'observed_at' => $price->observed_at?->toDateString(),
            'source' => $price->source->value,
            'source_label' => $price->source->label(),

            // Si el precio vino de una recepción, es un hecho: la mercancía llegó y eso se pagó. Una cotización es
            // una promesa. La distinción cambia cuánto se puede confiar en la comparación.
            'is_confirmed_purchase' => $price->source->isConfirmedPurchase(),

            'presentation' => $price->presentation === null ? null : [
                'ulid' => $price->presentation->ulid,
                'name' => $price->presentation->name,
                'quantity_in_base_unit' => $price->presentation->quantity_in_base_unit,
            ],

            // Lo capturado tal cual, para poder explicar el precio normalizado.
            'observed_quantity' => $price->observed_quantity,
            'observed_price' => $price->observed_price,
        ];
    }

    /**
     * Cuánto cambió, en monto y en porcentaje sobre el precio ANTERIOR.
     *
     * Sobre el anterior y no sobre el nuevo: subir de 10 a 15 es un 50 % de subida, no un 33 %. Es el error que hace
     * que las subidas parezcan menores de lo que son, y por eso el cálculo vive aquí y no en el cliente.
     *
     * @param  numeric-string  $from
     * @param  numeric-string  $to
     * @return array<string, mixed>
     */
    private function change(string $from, string $to): array
    {
        $amount = bcsub($to, $from, 4);

        return [
            'amount' => Decimal::round($amount, 4),
            'percent' => Decimal::round(bcmul(Decimal::divide($amount, $from, 8), '100', 8), 2),
            'direction' => match (bccomp($amount, '0', 4)) {
                1 => 'up',
                -1 => 'down',
                default => 'flat',
            },
        ];
    }
}
