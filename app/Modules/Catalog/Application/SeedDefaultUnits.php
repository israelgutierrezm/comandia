<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Enums\UnitDimension;
use App\Modules\Catalog\Infrastructure\Models\Unit;

/**
 * Siembra las unidades de medida con las que un negocio de alimentos puede empezar a trabajar.
 *
 * ## Por qué se siembran y no se dejan en blanco
 *
 * `articles.base_unit_id` es NOT NULL, así que un tenant con cero unidades **no puede capturar ni un
 * artículo**. Dejarlo en blanco convertiría el primer minuto del producto en un formulario que
 * obliga a inventar el sistema métrico antes de poder dar de alta un refresco.
 *
 * No es inventar una regla de negocio: son las unidades físicas del mercado mexicano de alimentos y
 * bebidas, y el tenant puede desactivarlas, renombrarlas o agregar las suyas (`catalog.units.manage`).
 *
 * Lo que **no** se siembra es una unidad por cada cosa imaginable —onzas, libras, galones—: un
 * catálogo lleno de unidades que el negocio no usa hace más lento elegir la que sí usa. Cinco
 * unidades cubren el caso normal y las demás se agregan cuando hagan falta.
 *
 * Idempotente: se puede correr dos veces sin duplicar. Hace falta porque el alta de un tenant podría
 * reintentarse, y porque el sembrado por evento no debe ser un camino frágil.
 */
final readonly class SeedDefaultUnits
{
    /**
     * Código => [nombre, dimensión, factor a la unidad base del sistema].
     *
     * Las bases del sistema (gramo, mililitro, pieza) llevan factor 1 por definición: son la
     * referencia contra la que se expresan las demás.
     *
     * @var array<string, array{string, UnitDimension, string}>
     */
    private const DEFAULTS = [
        'g' => ['Gramo', UnitDimension::Mass, '1'],
        'kg' => ['Kilogramo', UnitDimension::Mass, '1000'],
        'ml' => ['Mililitro', UnitDimension::Volume, '1'],
        'l' => ['Litro', UnitDimension::Volume, '1000'],
        'pza' => ['Pieza', UnitDimension::Count, '1'],
    ];

    /**
     * @return list<Unit>
     */
    public function seed(): array
    {
        $units = [];

        foreach (self::DEFAULTS as $code => [$name, $dimension, $factor]) {
            // `firstOrCreate` sobre el código: el índice único es (tenant, code) y el scope de tenant
            // ya acota la consulta, así que no hace falta —ni se debe— pasar `tenant_id`.
            $units[] = Unit::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'dimension' => $dimension,
                    'factor_to_base' => $factor,
                ],
            );
        }

        return $units;
    }
}
