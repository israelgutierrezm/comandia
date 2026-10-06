<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Application\PosItemConsumption;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Application\SystemWasteReasons;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registra como merma lo que la cocina ya había preparado y se canceló (D375, §6.3).
 *
 * ## Por qué hacía falta
 *
 * La venta descuenta al COBRAR, y lo cancelado no se cobra: el platillo que se tiró nunca salía del inventario. Los
 * insumos seguían «ahí» en el sistema, el siguiente conteo los daba por faltantes sin explicación, y la merma —que es
 * justo la zona de robo hormiga que §9 pide investigar— no aparecía en su reporte.
 *
 * ## Consume lo mismo que una venta, del mismo almacén
 *
 * La receta del platillo (o el artículo mismo) sale del almacén de su área, y sin área del de la sucursal: lo resuelve
 * `PosItemConsumption`, el mismo que usa la venta. Tirar un plato no puede consumir distinto de venderlo.
 *
 * Con destino `restock` no corre: nada se tocó, y como la venta nunca lo descontó, no hay nada que devolver.
 *
 * ## Con su motivo, sin pedir autorización otra vez
 *
 * Cada movimiento lleva el motivo del sistema «Cancelación en POS», y así aparece en el reporte de mermas. El umbral de
 * monto de `RegisterWaste` no aplica: esta merma ya la autorizó un superior con su PIN al cancelar, y pedirlo de nuevo
 * desde un trabajo en cola —donde no hay nadie que lo ponga— la dejaría sin registrar.
 *
 * ## Idempotente por (cuenta, item, componente)
 *
 * La llave es `pos_cancellation:{cuenta}:item:{item}:{componente}`. Re-despachar no duplica nada, y un item no puede
 * cancelarse dos veces, así que tampoco hay dos mermas legítimas del mismo item.
 *
 * ## Un fallo no se propaga
 *
 * La línea ya está cancelada cuando esto corre. Un item que revienta se registra y se sigue con los demás.
 */
final class RegisterPosCancellationWaste implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{item_ulid: string, article_id: int, quantity: numeric-string}>  $items
     */
    public function __construct(
        private readonly int $tenantId,
        private readonly int $branchId,
        private readonly string $accountUlid,
        private readonly string $accountDisplayName,
        private readonly ?int $preparationAreaId,
        private readonly array $items,
        private readonly int $actorMembershipId,
    ) {
        // `default`, como el descuento de la venta: nadie está esperando esto.
        $this->onQueue('default');
    }

    public function handle(
        TenantContext $context,
        RecordStockMovement $kardex,
        PosItemConsumption $consumption,
        SystemWasteReasons $reasons,
    ): void {
        // El contexto se restablece EXPLÍCITAMENTE: un job corre sin petición, y sin él el global scope no encontraría ni
        // los artículos.
        $context->runFor($this->tenantId, function () use ($kardex, $consumption, $reasons): void {
            $almacen = $consumption->areaWarehouse($this->preparationAreaId)
                ?? $consumption->branchWarehouse($this->branchId);

            if ($almacen === null) {
                // Configuración incompleta, no un error de la cancelación: se registra y se sigue.
                Log::warning('No hay almacén para registrar la merma de una cancelación del POS.', [
                    'tenant_id' => $this->tenantId,
                    'account' => $this->accountUlid,
                ]);

                return;
            }

            foreach ($this->items as $item) {
                try {
                    $this->wasteItem($item, $almacen, $kardex, $consumption, $reasons);
                } catch (Throwable $e) {
                    Log::error('No se pudo registrar la merma de un item cancelado.', [
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
     * @param  array{item_ulid: string, article_id: int, quantity: numeric-string}  $item
     */
    private function wasteItem(
        array $item,
        Warehouse $almacen,
        RecordStockMovement $kardex,
        PosItemConsumption $consumption,
        SystemWasteReasons $reasons,
    ): void {
        $article = Article::query()->whereKey($item['article_id'])->first();

        if ($article === null) {
            return;
        }

        $componentes = $consumption->componentsOf($article, $item['quantity']);

        // Un servicio no controla existencias: no hay merma que registrar, ni motivo que crear para ella.
        if ($componentes === []) {
            return;
        }

        // Fuera de la transacción: si dos trabajos crean el motivo a la vez, el que pierde tiene que poder leer el del
        // otro, y dentro de una transacción ya abierta su lectura no lo vería.
        $motivo = $reasons->posCancellation();

        // El kardex Y el motivo, atómicos: una merma sin motivo es lo que §6.2 prohíbe, y el kardex es inmutable.
        DB::transaction(function () use ($componentes, $item, $almacen, $motivo, $kardex): void {
            $ids = [];

            foreach ($componentes as [$componente, $cantidad]) {
                $ids[] = $kardex->record(
                    warehouse: $almacen,
                    article: $componente,
                    kind: StockMovementKind::Waste,
                    quantity: $cantidad,
                    idempotencyKey: sprintf(
                        'pos_cancellation:%s:item:%s:%s',
                        $this->accountUlid,
                        $item['item_ulid'],
                        $componente->ulid,
                    ),
                    notes: mb_substr('Cancelación en POS · '.$this->accountDisplayName, 0, 200),
                    actorMembershipId: $this->actorMembershipId,
                )->id;
            }

            // El motivo no viaja por el kardex, igual que en `RegisterWaste`: se escribe enseguida sobre los movimientos.
            // `whereNull` porque un re-despacho devuelve los que ya existían, y ésos ya lo tienen.
            StockMovement::query()
                ->whereIn('id', $ids)
                ->whereNull('waste_reason_id')
                ->toBase()
                ->update(['waste_reason_id' => $motivo->id]);
        });
    }
}
