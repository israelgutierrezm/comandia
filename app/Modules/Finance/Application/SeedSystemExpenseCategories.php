<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;

/**
 * Siembra las categorías de gasto del sistema.
 *
 * ## Por qué éstas SÍ se siembran y los motivos de merma no
 *
 * Los motivos de merma nacen vacíos a propósito (D27, D225): son las categorías de pérdida del negocio, y sembrarlas
 * sería inventar su forma de contar lo que se rompe. Las categorías de gasto de una cocina, en cambio, son las mismas en
 * todas partes — gas, luz, agua, renta, sueldos, mantenimiento— y una lista vacía sólo conseguiría que el primer gasto
 * urgente acabara en una categoría inventada al vuelo, con el reporte de gastos partido desde el primer día.
 *
 * Idempotente por nombre, por lo mismo que los métodos de pago: esto corre al dar de alta un negocio Y al sincronizar
 * uno que ya existía.
 */
final readonly class SeedSystemExpenseCategories
{
    /**
     * @return list<array{name: string, sort_order: int}>
     */
    public static function definitions(): array
    {
        return [
            ['name' => 'Insumos y mercancía', 'sort_order' => 10],
            ['name' => 'Sueldos y personal', 'sort_order' => 20],
            ['name' => 'Renta', 'sort_order' => 30],
            ['name' => 'Servicios (luz, agua, gas)', 'sort_order' => 40],
            ['name' => 'Mantenimiento y reparaciones', 'sort_order' => 50],
            ['name' => 'Limpieza y desechables', 'sort_order' => 60],
            ['name' => 'Impuestos y trámites', 'sort_order' => 70],
            ['name' => 'Otros gastos', 'sort_order' => 900],
        ];
    }

    /**
     * @return int cuántas se crearon en esta pasada
     */
    public function seed(): int
    {
        $creadas = 0;

        foreach (self::definitions() as $definicion) {
            if (ExpenseCategory::query()->where('name', $definicion['name'])->exists()) {
                continue;
            }

            $categoria = ExpenseCategory::create($definicion);

            // `is_system` por el query builder, por lo mismo que en los métodos de pago: abrirlo en `$fillable` dejaría
            // que un alta por API se declarara del sistema.
            ExpenseCategory::query()->whereKey($categoria->id)->toBase()->update(['is_system' => true]);

            $creadas++;
        }

        return $creadas;
    }
}
