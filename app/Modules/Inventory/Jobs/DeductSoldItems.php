<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Application\ResolveProductionConsumption;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Descuenta del inventario lo que se vendió. **El único camino asíncrono de la iteración** (§6.2).
 *
 * ## Por qué es asíncrono, y por qué eso NO es una optimización
 *
 * §6.2 dice que el POS nunca se bloquea por inventario. No es por velocidad: es que un platillo con receta de tres
 * niveles puede tocar veinte artículos, y cualquiera de ellos puede tener una receta mal capturada. Si eso corriera
 * dentro del cobro, un error de receta impediría cobrar — alguien con el cambio en la mano y una pantalla que dice que
 * no se pudo.
 *
 * Así que el dinero entra primero y el inventario se pone al día después. La contrapartida está aceptada desde §6.2:
 * **las existencias negativas están permitidas**, y durante unos segundos las existencias van atrasadas.
 *
 * ## Idempotente por (cuenta, item, componente)
 *
 * Re-despacharlo no duplica nada: la llave del kardex es `pos_account:{ulid}:item:{ulid}:{componente}`. Eso importa
 * porque el mecanismo de reparación de este sistema **es** re-despachar — no hay un botón de «recalcular inventario»
 * que recorra la venta desde cero, y no debe haberlo: recalcular sobre un kardex que ya tiene movimientos duplicaría
 * los que sí se escribieron.
 *
 * ## Qué se consume por cada item
 *
 * - **inventariable y no producible** → se consume él mismo.
 * - **producible** → se explota su receta con `ResolveProductionConsumption`, que ya aplica rendimiento y conversión
 *   de unidades.
 * - **ninguna de las dos** → no consume nada, y es legítimo: un servicio, una propina de la casa, un artículo que el
 *   negocio no controla por existencias.
 *
 * ## El almacén: el del ÁREA que lo preparó
 *
 * Un platillo sale de la cocina y descuenta del almacén de la cocina; una cerveza que el mesero saca de la nevera no
 * tiene área y descuenta del almacén de la sucursal. Es lo que hace que un conteo por área tenga sentido — sin esto,
 * todo saldría de un almacén único y el conteo de la barra nunca cuadraría.
 *
 * ## Un fallo NO se propaga hacia el cobro
 *
 * La cuenta ya está pagada cuando esto corre. Si un item revienta —una receta con un ciclo, un artículo borrado—, se
 * registra y se sigue con los demás: un platillo mal capturado no puede impedir que se descuenten los otros diecinueve.
 */
final class DeductSoldItems implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{item_ulid: string, article_id: int, quantity: numeric-string, preparation_area_id: int|null, is_courtesy: bool}>  $items
     */
    public function __construct(
        private readonly int $tenantId,
        private readonly int $branchId,
        private readonly string $accountUlid,
        private readonly array $items,
    ) {
        // La cola `default` y no `critical`: el cobro ya terminó. `critical` es para lo que la operación está
        // esperando, y aquí no espera nadie.
        $this->onQueue('default');
    }

    public function handle(
        TenantContext $context,
        RecordStockMovement $kardex,
        ResolveProductionConsumption $recipes,
    ): void {
        // El contexto se restablece EXPLÍCITAMENTE: un job corre en un proceso sin petición, así que no hay middleware
        // que lo haya puesto. Sin esto, el global scope no encontraría ni los artículos y el job no descontaría nada,
        // en silencio.
        $context->runFor($this->tenantId, function () use ($kardex, $recipes): void {
            $almacenSucursal = $this->branchWarehouse();

            foreach ($this->items as $item) {
                try {
                    $this->deductItem($item, $almacenSucursal, $kardex, $recipes);
                } catch (Throwable $e) {
                    // Un item que revienta no puede impedir que se descuenten los demás.
                    Log::error('No se pudo descontar un item vendido del inventario.', [
                        'tenant_id' => $this->tenantId,
                        'account' => $this->accountUlid,
                        'item' => $item['item_ulid'],
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * @param  array{item_ulid: string, article_id: int, quantity: numeric-string, preparation_area_id: int|null, is_courtesy: bool}  $item
     */
    private function deductItem(
        array $item,
        ?Warehouse $almacenSucursal,
        RecordStockMovement $kardex,
        ResolveProductionConsumption $recipes,
    ): void {
        $article = Article::query()->whereKey($item['article_id'])->first();

        if ($article === null) {
            return;
        }

        $almacen = $this->warehouseFor($item['preparation_area_id']) ?? $almacenSucursal;

        if ($almacen === null) {
            // Sin almacén no hay dónde descontar. Es configuración incompleta, no un error de la venta: se registra y
            // se sigue.
            Log::warning('No hay almacén para descontar un item vendido.', [
                'tenant_id' => $this->tenantId,
                'account' => $this->accountUlid,
                'item' => $item['item_ulid'],
            ]);

            return;
        }

        foreach ($this->componentsOf($article, $item['quantity'], $recipes) as [$componente, $cantidad]) {
            $kardex->record(
                warehouse: $almacen,
                article: $componente,
                kind: StockMovementKind::SaleConsumption,
                quantity: $cantidad,

                // La llave incluye el COMPONENTE: un platillo con tres insumos escribe tres movimientos, y sin el
                // componente en la llave el segundo chocaría con el primero y sólo se descontaría uno.
                idempotencyKey: sprintf(
                    'pos_account:%s:item:%s:%s',
                    $this->accountUlid,
                    $item['item_ulid'],
                    $componente->ulid,
                ),

                notes: $item['is_courtesy'] ? 'Cortesía' : null,
            );
        }
    }

    /**
     * Qué artículos y cuánto hay que descontar por vender esto.
     *
     * @return list<array{0: Article, 1: numeric-string}>
     */
    private function componentsOf(Article $article, string $quantity, ResolveProductionConsumption $recipes): array
    {
        if ($article->is_producible) {
            // El resolutor ya devuelve el `Article` del componente y la cantidad en su unidad base, con el rendimiento
            // y la conversión aplicados. Es el mismo que usa la producción, y eso es deliberado: si vender un platillo
            // consumiera distinto de producirlo, el inventario tendría dos verdades.
            return array_map(
                fn ($consumo): array => [$consumo->component, $consumo->quantityInBaseUnit],
                $recipes->forQuantity($article, $quantity),
            );
        }

        if ($article->is_inventoriable) {
            return [[$article, $quantity]];
        }

        // Ni producible ni inventariable: no hay nada que descontar, y es legítimo. Un servicio, una comisión, algo
        // que el negocio no controla por existencias.
        return [];
    }

    private function warehouseFor(?int $preparationAreaId): ?Warehouse
    {
        if ($preparationAreaId === null) {
            return null;
        }

        $area = PreparationArea::query()->whereKey($preparationAreaId)->first();

        return $area?->warehouse_id === null
            ? null
            : Warehouse::query()->whereKey($area->warehouse_id)->first();
    }

    private function branchWarehouse(): ?Warehouse
    {
        $branch = Branch::query()->whereKey($this->branchId)->first();

        if ($branch?->default_warehouse_id !== null) {
            return Warehouse::query()->whereKey($branch->default_warehouse_id)->first();
        }

        return Warehouse::query()->where('branch_id', $this->branchId)->orderBy('id')->first();
    }
}
