<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Reporting;

/**
 * Una columna por la que un reporte puede AGRUPAR (§6.7, ADR-006).
 *
 * La `$expression` es SQL que declara el módulo dueño en su definición —nunca entra por el cliente—, así que el motor la
 * usa tal cual para el `SELECT ... AS key` y el `GROUP BY`. Ejemplos: `pos_order_items.article_name` (el nombre congelado),
 * `article_categories.name` (una categoría por join). Lo que no está en la whitelist de dimensiones no se puede pedir.
 */
final readonly class Dimension
{
    public function __construct(
        public string $key,
        public string $expression,
        public string $label,
    ) {}
}
