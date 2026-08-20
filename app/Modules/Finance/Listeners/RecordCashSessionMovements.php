<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
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
