<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Domain\Enums\StockCountStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Domain\Exceptions\CountCloseRequiresAuthorizationException;
use App\Modules\Inventory\Domain\Exceptions\StockCountInvariantException;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Inventory\Infrastructure\Models\StockCountLine;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cierra un conteo y aplica las diferencias al kardex (D24, §6.2).
 *
 * Es la operación con más consecuencias del módulo: convierte una hoja de papel en doscientos movimientos de
 * inventario irreversibles. De ahí los cuatro controles que lleva, cada uno cubriendo algo que los otros no:
 *
 *   1. **Permiso propio**, `inventory.counts.close`. Quien cuenta no decide que su conteo es la verdad — el
 *      almacenista inicia y captura, y no cierra.
 *   2. **Umbral de monto con PIN**, igual que las mermas (D169). El permiso dice quién puede; el umbral dice cuánto
 *      puede absorber sin que nadie más firme.
 *   3. **Idempotencia por línea**. Cada ajuste lleva llave `stock_count:{id}:line:{id}`, así que un cierre
 *      interrumpido y reintentado no aplica nada dos veces.
 *   4. **Lock sobre el documento**. Dos cierres simultáneos del mismo conteo se serializan, y el segundo ve el
 *      estado ya cerrado y se rechaza.
 *
 * ## Quién autoriza no es quien cierra
 *
 * `inventory.counts.authorize_above_threshold` es del **propietario**, y se le quita explícitamente al gerente —el
 * único permiso del catálogo que se excluye por esta razón. Si lo tuviera el gerente, que es quien cierra, el
 * umbral no defendería nada: se autorizaría a sí mismo con su propio PIN.
 *
 * La consecuencia operativa hay que decirla: un cierre con descuadre grande **espera al propietario**. No se pierde
 * nada —el conteo sigue abierto y lo capturado sigue ahí— pero las diferencias no se aplican hasta que alguien con
 * autoridad sobre el patrimonio firme. Eso es lo que se quiere de un castigo de cincuenta mil pesos.
 *
 * Lo que este diseño **no** impide: que el propietario, cerrando él mismo, se autorice con su propio PIN. En un
 * negocio de una sola persona no hay alternativa —exigir un segundo actor lo dejaría sin poder cerrar nunca— y a
 * cambio queda el rastro: la bitácora dice que cerró y que autorizó, y son la misma persona.
 */
final class CloseStockCount
{
    public function __construct(
        private readonly RecordStockMovement $movements,
        private readonly PinAuthorizationService $pin,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly ContextHolder $context,
    ) {}

    /**
     * @throws StockCountInvariantException
     * @throws CountCloseRequiresAuthorizationException
     */
    public function close(StockCount $count, ?string $authorizationToken = null): StockCount
    {
        // La valuación y la decisión de autorizar van ANTES de la transacción y antes de tocar el kardex, por lo
        // mismo que en las mermas (D169): un cierre que se aplicara y después se rechazara dejaría doscientos
        // ajustes escritos —en una tabla inmutable— sin nadie que responda por ellos.
        $lines = $this->linesWithVariance($count);

        [$net, $gross] = $this->valuate($lines);

        $threshold = $this->threshold($count);
        $authorizer = $this->resolveAuthorizer($gross, $threshold, $authorizationToken);

        $closed = DB::transaction(function () use ($count, $net, $gross, $authorizer): StockCount {
            $locked = StockCount::query()->lockForUpdate()->whereKey($count->id)->sole();

            if (! $locked->isOpen()) {
                throw StockCountInvariantException::notOpen();
            }

            // Se releen DENTRO del lock. Las de fuera sirvieron para valuar y decidir la autorización; éstas son
            // las que se aplican. Entre las dos lecturas alguien pudo capturar un renglón más, y aplicar la lista
            // vieja dejaría ese renglón contado y sin ajuste — un conteo cerrado que no cuadra con sus líneas.
            $toApply = $this->linesWithVariance($locked);

            foreach ($toApply as $line) {
                $this->applyLine($locked, $line);
            }

            $locked->update([
                'status' => StockCountStatus::Closed,
                'closed_by_membership_id' => $this->context->getOrNull()?->membership?->id,
                'closed_at' => CarbonImmutable::now(),
                'variance_value' => $net,
                'variance_value_absolute' => $gross,
                'authorized_by_membership_id' => $authorizer?->id,
            ]);

            return $locked;
        });

        // A la bitácora técnica por lo mismo que las mermas (D172): es una pérdida con actor. Y con más razón —un
        // conteo puede castigar en un cierre lo que cien mermas no.
        $this->audit->log(
            action: AuditAction::STOCK_COUNT_CLOSED,
            auditable: $closed,
            after: [
                'warehouse' => $closed->warehouse?->code,
                'lines_adjusted' => $lines->count(),
                'variance_value' => $net,
                'variance_value_absolute' => $gross,
                'threshold' => $threshold,
            ],
            authorizedBy: $authorizer,
        );

        return $closed->refresh();
    }

    /**
     * Cancela un conteo sin aplicar nada.
     *
     * Existe porque la garantía de «un solo conteo abierto por almacén» lo vuelve necesario: sin cancelación, un
     * conteo empezado por error dejaría ese almacén sin poder contarse nunca más. Descarta lo capturado —las líneas
     * se conservan, pero no producen ningún movimiento— y va con el mismo permiso que cerrar: la misma autoridad
     * que decide que un conteo es la verdad decide que no lo es.
     *
     * @throws StockCountInvariantException
     */
    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $locked = StockCount::query()->lockForUpdate()->whereKey($count->id)->sole();

            if (! $locked->isOpen()) {
                throw StockCountInvariantException::notOpen();
            }

            $locked->update([
                'status' => StockCountStatus::Cancelled,
                'closed_by_membership_id' => $this->context->getOrNull()?->membership?->id,
                'closed_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        });
    }

    /**
     * @return Collection<int, StockCountLine>
     */
    private function linesWithVariance(StockCount $count): Collection
    {
        return StockCountLine::query()
            ->where('stock_count_id', $count->id)
            ->withVariance()
            ->whereNull('adjustment_movement_id')
            ->with(['article', 'lot'])
            ->get();
    }

    /**
     * El neto con signo y el bruto en valor absoluto.
     *
     * Las líneas sin costo capturado no suman a ninguno de los dos: su diferencia sí se aplica al kardex —la
     * cantidad es real— pero no vale pesos, y meterlas con un cero las contaría como si no hubieran pasado. Es la
     * misma consecuencia que en las mermas (D169) y con el mismo argumento.
     *
     * @param  Collection<int, StockCountLine>  $lines
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function valuate(Collection $lines): array
    {
        $net = '0.00';
        $gross = '0.00';

        foreach ($lines as $line) {
            $value = $line->varianceValue();

            if ($value === null) {
                continue;
            }

            $net = bcadd($net, $value, 2);
            $gross = bcadd($gross, ltrim($value, '-'), 2);
        }

        return [$net, $gross];
    }

    /**
     * @param  numeric-string  $gross
     * @param  numeric-string  $threshold
     *
     * @throws CountCloseRequiresAuthorizationException
     */
    private function resolveAuthorizer(
        string $gross,
        string $threshold,
        ?string $authorizationToken,
    ): ?TenantMembership {
        if (bccomp($gross, $threshold, 2) !== 1) {
            return null;
        }

        if ($authorizationToken === null) {
            throw CountCloseRequiresAuthorizationException::forValue($gross, $threshold);
        }

        return $this->pin->consume($authorizationToken, 'inventory.counts.authorize_above_threshold');
    }

    /**
     * @return numeric-string
     */
    private function threshold(StockCount $count): string
    {
        $branchId = $count->warehouse?->branch_id;

        $value = $branchId === null
            ? $this->settings->get('inventory.count_authorization_threshold')
            : $this->settings->forBranch('inventory.count_authorization_threshold', $branchId);

        return Decimal::round((string) $value, 2);
    }

    /**
     * Escribe el ajuste de una línea y lo enlaza con ella.
     *
     * La dirección sale del signo de la diferencia —sobrante entra, faltante sale— y la cantidad va en absoluto
     * porque el kardex la guarda siempre positiva con la dirección aparte (paso 1).
     *
     * El costo es el **congelado en la línea**, no el vigente. Si se releyera aquí, el ajuste se valuaría con un
     * costo distinto del que se comparó con el umbral, y el conteo cerrado no cuadraría con sus propios
     * movimientos.
     */
    private function applyLine(StockCount $count, StockCountLine $line): void
    {
        /** @var numeric-string $variance */
        $variance = $line->variance;

        $movement = $this->movements->record(
            warehouse: $count->warehouse,
            article: $line->article,
            kind: StockMovementKind::CountAdjustment,
            quantity: ltrim($variance, '-'),
            direction: bccomp($variance, '0', 4) === 1
                ? StockMovementDirection::In
                : StockMovementDirection::Out,
            lot: $line->lot,
            unitCost: $line->unit_cost_at_count,
            source: $count,

            // Por LÍNEA y no por conteo: un cierre interrumpido a la mitad se reintenta y las líneas ya aplicadas
            // devuelven su movimiento existente en lugar de duplicarlo.
            idempotencyKey: "stock_count:{$count->id}:line:{$line->id}",
            notes: 'Ajuste por conteo físico',
        );

        // El enlace de vuelta. Hace navegable el conteo → kardex, y hace **detectable** un cierre a medias: una
        // línea con diferencia y sin movimiento es un cierre que se interrumpió.
        $line->update(['adjustment_movement_id' => $movement->id]);
    }
}
