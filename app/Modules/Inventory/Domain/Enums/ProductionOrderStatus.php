<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Estados de una orden de producción (D17, P8).
 *
 * ```
 * draft → completed
 *      ↘ cancelled
 * ```
 *
 * ## Aquí el borrador SÍ tiene contenido, al contrario que en el conteo
 *
 * En el conteo se quitó (D175) porque no había ningún momento del trabajo real que le correspondiera. Aquí sí lo hay:
 * **producción planeada.** «Mañana hacemos veinte litros de salsa» es una decisión que se toma antes de tocar un
 * ingrediente, y sirve para saber qué hay que comprar.
 *
 * La diferencia entre los dos casos no es de gusto: un borrador de conteo no podía existir sin congelar lo esperado
 * —congelar era el primer acto— y un borrador de producción **no congela nada**, porque lo que se consume se decide
 * en el momento de producir.
 */
enum ProductionOrderStatus: string
{
    /** Planeada. No ha movido nada de inventario. */
    case Draft = 'draft';

    /** Ya se produjo: los insumos salieron y el producible entró. Inmutable. */
    case Completed = 'completed';

    /** Se descartó sin producir nada. */
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Planeada',
            self::Completed => 'Completada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
