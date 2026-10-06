<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Jobs\RegisterPosCancellationWaste;
use App\Modules\Shared\Domain\Events\PosItemsCancelled;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manda a la cola la merma de lo que se canceló ya preparado (D375).
 *
 * Sólo los items con destino `waste`: con `restock` nada se tocó, y como la venta nunca lo descontó —se descuenta al
 * cobrar, y lo cancelado no se cobra—, no hay nada que devolver. Si ninguno es merma, no se encola nada: un job vacío en
 * la cola es ruido que alguien acabará investigando.
 *
 * Igual que el descuento de la venta, el oyente sólo ENCOLA, y ni eso puede tumbar la cancelación: corre después del
 * commit, así que la línea ya está cancelada. Si la cola estuviera caída se registra el fallo; la reparación es volver a
 * despachar, que no duplica nada porque el job es idempotente.
 */
final readonly class RegisterCancellationWaste
{
    public function handle(PosItemsCancelled $event): void
    {
        $mermas = array_values(array_filter(
            $event->items,
            fn (array $item): bool => $item['destination'] === 'waste',
        ));

        if ($mermas === []) {
            return;
        }

        try {
            RegisterPosCancellationWaste::dispatch(
                $event->tenantId,
                $event->branchId,
                $event->accountUlid,
                $event->accountDisplayName,
                $event->preparationAreaId,
                array_map(fn (array $item): array => [
                    'item_ulid' => $item['item_ulid'],
                    'article_id' => $item['article_id'],
                    'quantity' => $item['quantity'],
                ], $mermas),
                $event->actorMembershipId,
            );
        } catch (Throwable $e) {
            Log::error('No se pudo encolar la merma de una cancelación del POS.', [
                'tenant_id' => $event->tenantId,
                'account' => $event->accountUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
