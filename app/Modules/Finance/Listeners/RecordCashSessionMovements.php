<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\CalculateSessionCut;
use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Shared\Domain\Events\PosSessionClosed;
use App\Modules\Shared\Domain\Events\PosSessionOpened;
use App\Modules\Shared\Domain\Events\PosWithdrawalRegistered;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Asienta en el diario lo que ocurre en una caja: el fondo de apertura y los retiros.
 *
 * ## El PRIMER oyente que cruza módulos con el contrato de D231
 *
 * `Finance` escucha eventos del **kernel** con datos primitivos, así que no conoce el módulo `Pos` ni al revés. La
 * flecha de dependencia no existe en ninguna dirección — que es exactamente lo que el paso 1 dejó preparado y lo que
 * evita el nudo que la Iteración 3 tuvo que romper a mano (D209).
 *
 * ## Y NO puede tumbar la operación
 *
 * Los eventos se despachan después del commit, así que cuando esto corre la caja **ya está abierta** o el retiro **ya
 * ocurrió**. Si el asiento falla, el fallo se registra y no se propaga.
 *
 * Es la lección de D220, aplicada desde el diseño y no después: en la Iteración 3 un oyente que lanzaba hizo que una
 * confirmación de compra respondiera 422 con la mercancía ya en el kardex, y quien confirmó creyó que no había pasado
 * nada. Aquí sería peor — «no se pudo abrir la caja» con la caja abierta deja al cajero intentándolo otra vez, y el
 * índice único le diría que ya hay un turno abierto sin que él lo vea en ninguna pantalla.
 *
 * El asiento es **idempotente por (documento, tipo)**, así que re-despachar el evento repara lo que falte. Ése es el
 * mecanismo de reparación, no un `try` que reintenta a ciegas.
 */
final readonly class RecordCashSessionMovements
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
        private CalculateSessionCut $cut,
    ) {}

    public function handleOpened(PosSessionOpened $event): void
    {
        $this->safely($event->tenantId, 'apertura de caja', $event->sessionUlid, function () use ($event): void {
            // Un fondo de CERO es legítimo —una caja que abre sin cambio— y el diario rechaza asientos en cero, con
            // razón: si no hubo dinero, no hubo hecho. Así que no se asienta, y el corte lo trata como fondo cero.
            if (Decimal::round($event->openingFloat, 2) === '0.00') {
                return;
            }

            $this->journal->record(
                branchId: $event->branchId,
                type: FinancialMovementType::OpeningFloat,
                amount: $event->openingFloat,
                sourceType: 'App\\Modules\\Pos\\Infrastructure\\Models\\PosSession',
                sourceUlid: $event->sessionUlid,
                actorMembershipId: $event->actorMembershipId,
                posSessionId: $event->sessionId,
                occurredAt: CarbonImmutable::parse($event->openedAt),
            );
        });
    }

    /**
     * Al cerrar la caja: la DIFERENCIA de efectivo entre lo declarado y lo esperado.
     *
     * ## La diferencia es ella misma un movimiento tipado (§6.5)
     *
     * No se guarda en la sesión ni se recalcula al mirarla: se asienta. Así el diario **cuadra consigo mismo** —la suma
     * de lo que afecta al cajón, incluida la diferencia, es lo que de verdad había— y la diferencia queda con nombre,
     * monto y actor, que es lo que permite preguntar «¿a esta persona le falta dinero seguido?».
     *
     * ## Sólo la del EFECTIVO se asienta, y las de otros métodos no
     *
     * Mi primera versión asentaba una diferencia por método, y chocaba consigo misma: la llave de idempotencia del
     * diario es `(documento, tipo)`, así que dos métodos de la misma sesión producirían la misma llave y sólo se
     * asentaría el primero. Intenté componer el `source_ulid` con el método y no cabe — la columna es `CHAR(26)`, un
     * ULID exacto.
     *
     * Al replantearlo, la versión por método era además la equivocada. El diario modela **el dinero del negocio**, y una
     * discrepancia con la terminal bancaria no cambia cuánto dinero hay: cambia qué hay que reclamarle al banco. Eso es
     * conciliación —lo que D38 dejó fuera— y meterlo aquí haría que un error de la terminal se viera como un faltante
     * de caja, que es una acusación muy distinta.
     *
     * Las diferencias de los demás métodos **sí se muestran** en el reporte del corte. Lo que no hacen es mover el
     * diario.
     *
     * ## Una diferencia de CERO no se asienta
     *
     * El diario rechaza los asientos en cero (paso 4) y aquí eso es lo correcto: «cuadró» es información, pero es la
     * ausencia de diferencia, no una diferencia de cero. Un asiento por cada turno que cuadra llenaría el diario de
     * renglones que no dicen nada.
     *
     * ## El signo se conserva tal cual
     *
     * `count_difference` tiene signo natural CERO —«cualquiera de los dos es legítimo»— porque puede sobrar o faltar.
     * Positivo: había más de lo esperado. Negativo: faltaba. Normalizarlo perdería la mitad de la información.
     */
    public function handleClosed(PosSessionClosed $event): void
    {
        $this->safely($event->tenantId, 'diferencia de corte', $event->sessionUlid, function () use ($event): void {
            $efectivo = PaymentMethod::query()->where('code', 'CASH')->first();

            if ($efectivo === null) {
                return;
            }

            $declarado = '0.00';

            foreach ($event->declarations as $declaracion) {
                if ($declaracion['payment_method_id'] === (int) $efectivo->id) {
                    $declarado = Decimal::round($declaracion['declared_amount'], 2);
                }
            }

            $diferencia = bcsub($declarado, $this->cut->expectedCash($event->sessionId), 2);

            if (bccomp($diferencia, '0', 2) === 0) {
                return;
            }

            $this->journal->record(
                branchId: $event->branchId,
                type: FinancialMovementType::CountDifference,
                amount: $diferencia,
                sourceType: 'App\Modules\Pos\Infrastructure\Models\PosSession',
                sourceUlid: $event->sessionUlid,
                actorMembershipId: $event->actorMembershipId,
                posSessionId: $event->sessionId,
                occurredAt: CarbonImmutable::parse($event->closedAt),
            );
        });
    }

    public function handleWithdrawal(PosWithdrawalRegistered $event): void
    {
        $this->safely($event->tenantId, 'retiro de caja', $event->withdrawalUlid, function () use ($event): void {
            // EN NEGATIVO: el retiro sale del cajón. El signo lo pone aquí y no el emisor, usando el sentido natural
            // del tipo — poner un retiro en positivo dejaría el arqueo cuadrando al revés, y es el error más fácil de
            // cometer.
            $signo = FinancialMovementType::Withdrawal->naturalSign();

            $this->journal->record(
                branchId: $event->branchId,
                type: FinancialMovementType::Withdrawal,
                amount: Decimal::round(bcmul($event->amount, (string) $signo, 4), 2),
                sourceType: 'App\\Modules\\Pos\\Infrastructure\\Models\\PosSessionWithdrawal',
                sourceUlid: $event->withdrawalUlid,
                actorMembershipId: $event->actorMembershipId,
                posSessionId: $event->sessionId,
                occurredAt: CarbonImmutable::parse($event->occurredAt),
            );
        });
    }

    /**
     * Corre el asiento con el contexto del negocio abierto, y sin poder tumbar lo que ya ocurrió.
     *
     * El contexto se fija explícitamente porque un oyente puede correr desde una cola, donde no hay sesión ni petición,
     * y sin esto los global scopes no sabrían de qué negocio leer. El `tenantId` viaja en el evento justamente para
     * esto — es parte del contrato `CrossModuleEvent`.
     */
    private function safely(int $tenantId, string $que, string $documentUlid, callable $asentar): void
    {
        try {
            $this->tenants->runFor($tenantId, $asentar);
        } catch (\Throwable $e) {
            // Se registra con el documento y el oyente, que es lo que permite re-despachar el evento para repararlo. Y
            // NO se propaga: la operación de caja ya ocurrió.
            Log::error('No se pudo asentar en el diario la '.$que, [
                'listener' => self::class,
                'tenant_id' => $tenantId,
                'document_ulid' => $documentUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
