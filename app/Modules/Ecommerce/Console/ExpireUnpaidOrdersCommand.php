<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Console;

use App\Modules\Ecommerce\Application\CloseUnpaidOrder;
use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Ecommerce\Domain\UnpaidOrderPolicy;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Vence los pedidos en línea que siguen sin pago (D380): a los 45 minutos pasan a «Pago vencido» y devuelven su cupón.
 *
 * Recorre los negocios uno por uno, cada uno en su contexto, como `comandia:costs:rebuild`: es mantenimiento, no una
 * consulta de dominio entre negocios. Los marketplaces no pasan por aquí: llegan cobrados.
 */
final class ExpireUnpaidOrdersCommand extends Command
{
    protected $signature = 'comandia:online-orders:expire
        {--tenant= : Slug o ULID de un negocio concreto; si se omite, todos}';

    protected $description = 'Cierra como «Pago vencido» los pedidos en línea que siguen sin pago y devuelve su cupón.';

    public function handle(TenantContext $context, CloseUnpaidOrder $closer): int
    {
        $identificador = $this->option('tenant');

        $negocios = $identificador === null
            ? Tenant::query()->orderBy('id')->get()
            : Tenant::query()->where('slug', $identificador)->orWhere('ulid', $identificador)->get();

        $limite = CarbonImmutable::now()->subMinutes(UnpaidOrderPolicy::EXPIRE_AFTER_MINUTES);
        $total = 0;

        foreach ($negocios as $negocio) {
            $total += $context->runFor((int) $negocio->id, function () use ($closer, $limite): int {
                $vencidos = 0;

                // Por LLAVE y no por página: cada pedido que se cierra sale del filtro, y paginar por OFFSET se saltaría
                // los de la página siguiente.
                Order::query()
                    ->where('status', OnlineOrderStatus::PendingPayment->value)
                    ->where('created_at', '<', $limite)
                    ->chunkById(200, function ($pedidos) use ($closer, &$vencidos): void {
                        foreach ($pedidos as $order) {
                            if ($closer->close($order, OnlineOrderStatus::Expired)->status === OnlineOrderStatus::Expired) {
                                $vencidos++;
                            }
                        }
                    });

                return $vencidos;
            });
        }

        $this->components->info(sprintf('%d pedido(s) vencidos por falta de pago.', $total));

        return self::SUCCESS;
    }
}
