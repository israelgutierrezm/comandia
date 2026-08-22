<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Reporting;

/**
 * Una columna AGREGADA de un reporte: la cifra que se suma/cuenta/promedia (§6.7, ADR-006).
 *
 * La `$expression` es una expresión SQL de agregación completa que declara el módulo dueño —`SUM(...)`, `COUNT(...)`,
 * o un cociente como el margen `ROUND(SUM(neto-costo)/NULLIF(SUM(neto),0)*100, 2)`—, nunca entra por el cliente. El motor
 * la coloca como `expression AS key`. El `$format` le dice al frontend cómo presentarla (dinero, cantidad, porcentaje,
 * entero); el dinero ya llega redondeado del servidor (D134): el frontend no re-suma ni re-redondea.
 */
final readonly class Measure
{
    public function __construct(
        public string $key,
        public string $expression,
        public string $label,
        public string $format = 'money',
    ) {}
}
