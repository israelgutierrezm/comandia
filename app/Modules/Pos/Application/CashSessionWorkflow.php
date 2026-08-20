<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Domain\Enums\PosSessionStatus;
use App\Modules\Pos\Domain\Exceptions\CashSessionException;
use App\Modules\Pos\Domain\Exceptions\WithdrawalRequiresAuthorizationException;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Pos\Infrastructure\Models\PosSessionDeclaration;
use App\Modules\Pos\Infrastructure\Models\PosSessionWithdrawal;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Events\PosSessionClosed;
use App\Modules\Shared\Domain\Events\PosSessionOpened;
use App\Modules\Shared\Domain\Events\PosWithdrawalRegistered;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Abrir, precortar, cerrar y retirar de una caja (§6.3).
 *
 * ## Qué NO hace este servicio
 *
 * No calcula el corte. El arqueo —esperado del diario contra lo declarado, y el asiento de la diferencia— llega en el
 * paso 19 con su propio servicio, porque depende de sumar el diario y eso es responsabilidad de `Finance`. Aquí se
 * registran los **hechos**: se abrió con este fondo, se declaró esto, se retiró aquello, se cerró a esta hora.
 *
 * Separarlo así es lo que permite que el cierre funcione desde este paso y el arqueo llegue después sin tocar nada de
 * esto.
 *
 * ## Los efectos en el diario van por EVENTOS
 *
 * El POS no escribe en finanzas (§2, regla 3). Emite `PosSessionOpened`, `PosWithdrawalRegistered` y
 * `PosSessionClosed`, y `Finance` asienta. Los tres son eventos del **kernel** con datos primitivos (D231), así que
 * ninguno de los dos módulos declara depender del otro.
 */
final readonly class CashSessionWorkflow
{
    private const DOCUMENT_TYPE = 'pos_session';

    private const SERIES = 'A';

    public function __construct(
        private DocumentNumberAllocator $folios,
        private ContextHolder $context,
        private PinAuthorizationService $pin,
    ) {}

    /**
     * Abre una caja con su fondo.
     *
     * @param  numeric-string  $openingFloat
     */
    public function open(Terminal $terminal, string $openingFloat): PosSession
    {
        $membershipId = $this->requireMembershipId();

        // La comprobación previa existe para dar un MENSAJE, no para garantizar nada: la garantía es el índice único
        // sobre la columna generada. Sin ella, dos cajeros abriendo a la vez al empezar el turno recibirían un error de
        // clave duplicada de MySQL en lugar de «esta caja ya tiene un turno abierto».
        $abierta = PosSession::query()->open()->where('terminal_id', $terminal->id)->first();

        if ($abierta !== null) {
            throw CashSessionException::terminalAlreadyOpen(
                (string) $terminal->code,
                $abierta->folioNumber(),
            );
        }

        try {
            $session = DB::transaction(function () use ($terminal, $openingFloat, $membershipId): PosSession {
                // El folio se toma DENTRO de la transacción: el allocator lo exige y falla si no la hay, porque fuera
                // de transacción el lock se libera de inmediato y dos peticiones tomarían el mismo número (§7).
                $folio = $this->folios->next((int) $terminal->branch_id, self::DOCUMENT_TYPE, self::SERIES);

                return PosSession::create([
                    'branch_id' => $terminal->branch_id,
                    'terminal_id' => $terminal->id,
                    'series' => self::SERIES,
                    'folio' => $folio,
                    'status' => PosSessionStatus::Open,
                    'opening_float' => Decimal::round($openingFloat, 2),
                    'opened_by_membership_id' => $membershipId,
                    'opened_at' => CarbonImmutable::now(),
                ])->refresh();
            });
        } catch (QueryException $e) {
            // La carrera real: dos peticiones pasaron la comprobación previa y la base rechazó la segunda. Se traduce
            // al mismo mensaje de dominio en lugar de dejar salir un 500 con jerga de MySQL.
            if (str_contains($e->getMessage(), 'pos_sessions_one_open_per_terminal_unique')) {
                throw CashSessionException::terminalAlreadyOpen((string) $terminal->code, '—');
            }

            throw $e;
        }

        // Después del commit: si el oyente del diario falla, la caja YA está abierta y eso es lo correcto. Un fallo al
        // asentar el fondo no puede impedir que el negocio empiece a cobrar — es la lección de D220.
        PosSessionOpened::dispatch(
            (int) $session->tenant_id,
            (string) $session->ulid,
            (int) $session->id,
            (int) $session->branch_id,
            Decimal::round($openingFloat, 2),
            $membershipId,
            $session->opened_at->toIso8601String(),
        );

        return $session;
    }

    /**
     * Declara lo que hay, en el precorte o en el cierre.
     *
     * @param  array<string, numeric-string>  $amountsByMethodUlid
     */
    public function declare(PosSession $session, string $moment, array $amountsByMethodUlid): PosSession
    {
        $this->assertOperable($session);

        $membershipId = $this->requireMembershipId();

        DB::transaction(function () use ($session, $moment, $amountsByMethodUlid, $membershipId): void {
            foreach ($amountsByMethodUlid as $methodUlid => $amount) {
                $method = PaymentMethod::query()->where('ulid', $methodUlid)->sole();

                // `updateOrCreate` y no `create`: volver a declarar el mismo método en el mismo momento es corregir un
                // dedazo de conteo, y mientras el arqueo no ha ocurrido eso no borra evidencia — está contando otra vez.
                PosSessionDeclaration::updateOrCreate(
                    [
                        'pos_session_id' => $session->id,
                        'moment' => $moment,
                        'payment_method_id' => $method->id,
                    ],
                    [
                        'declared_amount' => Decimal::round($amount, 2),
                        'declared_by_membership_id' => $membershipId,
                        'declared_at' => CarbonImmutable::now(),
                    ],
                );
            }

            // El precorte sella la sesión: deja constancia de que ocurrió y de quién lo hizo, sin cerrar la caja.
            if ($moment === 'precount') {
                $session->update([
                    'status' => PosSessionStatus::Precounted,
                    'precounted_by_membership_id' => $membershipId,
                    'precounted_at' => CarbonImmutable::now(),
                ]);
            }
        });

        return $session->refresh();
    }

    /**
     * Retira efectivo de la caja.
     *
     * @param  numeric-string  $amount
     */
    public function withdraw(PosSession $session, string $amount, string $reason, ?string $authorizationToken = null): PosSessionWithdrawal
    {
        $this->assertOperable($session);

        $membershipId = $this->requireMembershipId();

        // El retiro SIEMPRE exige PIN, sin umbral, y ahí está la diferencia con una merma. Una merma pequeña es un vaso
        // roto; un retiro pequeño es dinero saliendo del cajón, y §6.3 pone los retiros en la lista de acciones
        // sensibles sin excepción de monto. Un umbral aquí sería una puerta con una altura mínima.
        if ($authorizationToken === null) {
            throw WithdrawalRequiresAuthorizationException::forAmount(Decimal::round($amount, 2));
        }

        // `consume` revalida el permiso y el estado de la membresía, y gasta la concesión: una autorización sirve para
        // UNA operación.
        $authorizer = $this->pin->consume($authorizationToken, 'pos.sessions.withdraw');

        $withdrawal = DB::transaction(fn (): PosSessionWithdrawal => PosSessionWithdrawal::create([
            'pos_session_id' => $session->id,
            'amount' => Decimal::round($amount, 2),
            'reason' => $reason,
            'performed_by_membership_id' => $membershipId,
            'authorized_by_membership_id' => $authorizer?->id,
        ])->refresh());

        PosWithdrawalRegistered::dispatch(
            (int) $session->tenant_id,
            (string) $withdrawal->ulid,
            (int) $session->id,
            (int) $session->branch_id,
            Decimal::round($amount, 2),
            $reason,
            $membershipId,
            $withdrawal->created_at->toIso8601String(),
        );

        return $withdrawal;
    }

    /**
     * Cierra la caja.
     *
     * ## Exige declaraciones, y por eso no puede cerrarse a ciegas
     *
     * Cerrar sin declarar nada dejaría una sesión cerrada sin arqueo posible: el corte del paso 19 compara lo declarado
     * contra lo esperado, y sin lo primero no hay comparación — sólo un turno que terminó. Se exige al menos una
     * declaración de cierre.
     */
    public function close(PosSession $session, ?string $notes = null): PosSession
    {
        $this->assertOperable($session);

        if (! $session->hasDeclarationsFor('close')) {
            throw CashSessionException::closeNeedsDeclarations($session->folioNumber());
        }

        $membershipId = $this->requireMembershipId();

        DB::transaction(function () use ($session, $notes, $membershipId): void {
            $session->update([
                'status' => PosSessionStatus::Closed,
                'closed_by_membership_id' => $membershipId,
                'closed_at' => CarbonImmutable::now(),
                'closing_notes' => $notes,
            ]);
        });

        $session->refresh();

        PosSessionClosed::dispatch(
            (int) $session->tenant_id,
            (string) $session->ulid,
            (int) $session->id,
            (int) $session->branch_id,
            $membershipId,
            $session->closed_at->toIso8601String(),
        );

        return $session;
    }

    /**
     * Una caja cerrada no admite nada más.
     *
     * Se comprueba en el servicio y no sólo en el controlador porque el POS va a llamar a esto desde varios sitios
     * —cobrar, retirar, declarar— y la regla tiene que valer igual en todos.
     */
    private function assertOperable(PosSession $session): void
    {
        if (! $session->isOpen()) {
            throw CashSessionException::sessionAlreadyClosed($session->folioNumber());
        }
    }

    /**
     * La membresía que opera, exigida.
     *
     * Sin membresía en contexto no hay a quién atribuir el turno, y un arqueo sin actor no sirve para nada. Se lanza en
     * lugar de dejar `null`: es un error de programación llegar aquí sin contexto.
     */
    private function requireMembershipId(): int
    {
        $membership = $this->context->get()->membership ?? null;

        if ($membership === null) {
            throw CashSessionException::membershipRequired();
        }

        return (int) $membership->id;
    }
}
