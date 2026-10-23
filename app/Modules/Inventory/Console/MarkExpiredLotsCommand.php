<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Application\LotCalendar;
use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Marca «Caducado» los lotes activos cuya caducidad ya pasó y que todavía tienen existencia (D377); los que ya se acabaron,
 * «Agotado».
 *
 * La salida automática ya no los surte aunque nadie los marque; esto es para que el almacenista VEA qué partidas caducaron
 * y registre su merma. El saldo no se toca: dar la mercancía por tirada sigue siendo de una persona.
 *
 * «Hoy» es el día más atrasado de las sucursales del negocio, así que correrla a cualquier hora nunca adelanta una
 * caducidad. Recorre los negocios uno por uno, cada uno en su contexto, como `comandia:costs:rebuild`: es mantenimiento,
 * no una consulta de dominio entre negocios.
 *
 * Sin índice nuevo: filtra los lotes de UN negocio por estado y caducidad, una vez al día, y los lotes de un negocio son
 * cientos; el índice no pagaría lo que cuesta mantenerlo en cada recepción.
 */
final class MarkExpiredLotsCommand extends Command
{
    /** El saldo sumado del lote en todos sus almacenes (el lote es del artículo, P3). */
    private const SALDO = '(select coalesce(sum(article_stocks.quantity), 0) from article_stocks where article_stocks.lot_id = article_lots.id)';

    protected $signature = 'comandia:lots:mark-expired
        {--tenant= : Slug o ULID de un negocio concreto; si se omite, todos}';

    protected $description = 'Marca «Caducado» los lotes activos cuya caducidad ya pasó.';

    public function handle(TenantContext $context, LotCalendar $calendar): int
    {
        $identificador = $this->option('tenant');

        $negocios = $identificador === null
            ? Tenant::query()->orderBy('id')->get()
            : Tenant::query()->where('slug', $identificador)->orWhere('ulid', $identificador)->get();

        $total = 0;

        foreach ($negocios as $negocio) {
            $total += $context->runFor((int) $negocio->id, function () use ($calendar): int {
                $hoy = $calendar->todayInBusiness()->toDateString();

                $vencidos = ArticleLot::query()
                    ->where('status', LotStatus::Active->value)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', $hoy);

                // «Ya no hay» y «ya no sirve» son cosas distintas (ver `LotStatus`): un lote vencido que ya se acabó —los
                // que se consumieron antes de D377 nunca pasaron a «Agotado»— se marca agotado, no caducado, y no se
                // queda en la lista de lo que espera su merma.
                (clone $vencidos)
                    ->whereRaw(self::SALDO.' <= 0')
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_movements')->whereColumn('stock_movements.lot_id', 'article_lots.id'))
                    ->update(['status' => LotStatus::Depleted->value]);

                return (clone $vencidos)
                    ->whereRaw(self::SALDO.' > 0')
                    ->update(['status' => LotStatus::Expired->value]);
            });
        }

        $this->components->info(sprintf('%d lote(s) marcados como caducados.', $total));

        return self::SUCCESS;
    }
}
