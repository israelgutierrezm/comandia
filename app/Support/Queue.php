<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogo cerrado de colas de Comandia (ARQUITECTURA_MAESTRA §6).
 *
 * Todo job del sistema declara su cola con este enum. Prohibido escribir el
 * nombre de una cola como cadena literal: un nombre mal escrito produce un job
 * que nadie procesa y una consecuencia de negocio que nunca ocurre.
 *
 * Los workers se levantan con:
 *   php artisan queue:work redis --queue=critical,default,exports,printing
 * El orden importa: Laravel drena de izquierda a derecha por prioridad.
 */
enum Queue: string
{
    /**
     * Consecuencias que afectan la verdad contable u operativa del negocio:
     * movimientos del diario financiero, descuento de inventario por receta,
     * kardex. Se pierde una de estas y el negocio queda descuadrado.
     */
    case Critical = 'critical';

    /**
     * Consecuencias diferibles: notificaciones internas, recálculo de precios
     * sugeridos en cascada, proyecciones no contables.
     */
    case Default = 'default';

    /**
     * Reportes y exportaciones PDF/Excel. Aislada porque un export de un año
     * de ventas no debe retrasar un movimiento de diario.
     */
    case Exports = 'exports';

    /**
     * Trabajos de impresión de comandas y tickets. Aislada porque su latencia
     * la percibe la cocina en tiempo real y sus fallos son de infraestructura
     * física (impresora apagada), no de negocio.
     */
    case Printing = 'printing';

    /**
     * Orden de prioridad para el worker. Ver docs/ENTORNO_LOCAL.md.
     *
     * @return list<string>
     */
    public static function workerOrder(): array
    {
        return [
            self::Critical->value,
            self::Default->value,
            self::Exports->value,
            self::Printing->value,
        ];
    }
}
