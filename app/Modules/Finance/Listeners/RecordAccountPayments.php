<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Shared\Domain\Events\PosAccountPaid;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asienta en el diario lo que dejó una cuenta pagada: la venta, sus pagos, la propina y el cambio.
 *
 * ## Cuatro tipos de asiento para un solo cobro, y cada uno contesta otra pregunta
 *
 * - **`sale`** — cuánto se vendió. Es la cifra del reporte de ventas, y no coincide con lo que entró al cajón.
 * - **`payment`** — cuánto entró, **por método**. Literalmente lo ENTREGADO, no el importe de la cuenta: es de donde
 *   sale el «esperado» de cada método en el corte, y tiene que ser dinero de verdad para que el cajón cuadre.
 * - **`change`** — cuánto salió como cambio. Sólo del efectivo.
 * - **`tip`** — cuánta propina se dejó y a quién. No es venta del negocio: es dinero de un mesero que pasa por la caja,
 *   y por eso se liquida aparte (paso 18). **No mueve el cajón por sí misma**: ya viene dentro de lo entregado, y
 *   sumarla otra vez la contaría dos veces.
 *
 * Sumarlos en uno solo daría un número que no sirve para nada: ni cuadra el cajón ni mide la venta.
 *
 * ## Las líneas vienen en el EVENTO, y no se leen de `pos_payments`
 *
 * Escribí primero la versión que las leía, y crea un **ciclo**: `Pos` ya depende de `Finance` desde el paso 6, así que
 * importar `PosPayment` aquí cerraría el círculo. El acoplamiento en ambos sentidos entre el punto de venta y las
 * finanzas es exactamente lo que ADR-001 evita, y el sitio donde peor se paga es el dinero.
 *
 * Mi argumento para leerlas —«que el desglose salga de la evidencia y no de una copia»— estaba mal planteado: el
 * desglose **es el hecho**, no una copia del hecho. Va en primitivos, como pide D231, igual que `PosItemsCancelled` lleva
 * sus items desde el paso 8.
 *
 * ## El origen del asiento es el PAGO, no la cuenta
 *
 * La idempotencia del diario es por `(documento, tipo)`. Con la cuenta como origen, dos líneas de pago de la misma
 * cuenta chocarían entre sí y sólo se asentaría la primera: una cuenta pagada mitad efectivo y mitad tarjeta perdería
 * la mitad del dinero en el corte, sin que nada fallara.
 *
 * ## Y NO puede tumbar el cobro
 *
 * Corre después del commit: el dinero ya entró y la cuenta ya está pagada. Un fallo se registra y no se propaga (D220).
 * La reparación es re-despachar el evento, porque el asiento es idempotente.
 */
final readonly class RecordAccountPayments
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
    ) {}

    /**
     * Cuánto ENTRÓ por esta línea de cobro.
     *
     * Es el importe más la propina más el cambio. No hace falta que el evento traiga «lo entregado»: esa suma **es** lo
     * entregado por construcción, porque el cambio se calculó como `entregado − (importe + propina)`. Con un método que
     * no admite cambio el sumando vale cero y queda el importe más la propina, que es lo correcto para una tarjeta.
     *
     * ## El defecto que corrige, que hacía ver corta toda caja con cambio
     *
     * Se asentaba `amount` (196) mientras el cambio se asentaba entero (−84). La entrada iba **neta** y la salida
     * **bruta**, así que el cambio se descontaba dos veces: un cobro de 196 con 300 entregados y 20 de propina dejaba
     * el corte en 932 cuando el cajón tenía 1016. Al cajero se le achaca un faltante exacto al cambio que dio.
     *
     * Ninguna prueba lo veía porque las del corte pagan **exacto** (`tendered = total + propina`), y con cambio cero el
     * asiento del cambio ni siquiera se crea. El defecto vivía justo en el caso que nadie escribía.
     *
     * ## Por qué así y no restando el cambio del cajón
     *
     * Porque cada movimiento físico queda como un asiento: entra lo entregado, sale el cambio. Es lo que dice §6.5 y lo
     * que este mismo encabezado declara de `payment` —«cuánto entró, por método»—. La alternativa era dejar de restar
     * el cambio, que son menos líneas pero deja una regla tácita («la entrada va neta») del tipo con el que este
     * proyecto ya ha tropezado varias veces.
     *
     * @param  array{amount: numeric-string, change_amount: numeric-string, tip_amount: numeric-string}  $pago
     * @return numeric-string
     */
    private function entrada(array $pago): string
    {
        return bcadd(
            bcadd(Decimal::round($pago['amount'], 2), Decimal::round($pago['tip_amount'] ?? '0', 2), 2),
            Decimal::round($pago['change_amount'] ?? '0', 2),
            2,
        );
    }

    public function handle(PosAccountPaid $event): void
    {
        try {
            $this->tenants->runFor($event->tenantId, function () use ($event): void {
                $this->record($event);
            });
        } catch (Throwable $e) {
            Log::error('No se pudo asentar el cobro en el diario.', [
                'tenant_id' => $event->tenantId,
                'account' => $event->accountUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function record(PosAccountPaid $event): void
    {
        $ocurrio = CarbonImmutable::parse($event->paidAt);

        // La VENTA: lo que el negocio vendió, con independencia de cómo se pagó.
        if (Decimal::round($event->total, 2) !== '0.00') {
            $this->journal->record(
                branchId: $event->branchId,
                type: FinancialMovementType::Sale,
                amount: $event->total,
                sourceType: 'App\\Modules\\Pos\\Infrastructure\\Models\\PosAccount',
                sourceUlid: $event->accountUlid,
                actorMembershipId: $event->actorMembershipId,
                posSessionId: $event->posSessionId,
                occurredAt: $ocurrio,
            );
        }

        // Los métodos de una vez: son dos o tres por cuenta, y consultarlos por línea sería una consulta por pago.
        $metodos = PaymentMethod::query()
            ->whereIn('id', array_column($event->payments, 'payment_method_id'))
            ->get()
            ->keyBy('id');

        $origenPago = 'App\\Modules\\Pos\\Infrastructure\\Models\\PosPayment';

        foreach ($event->payments as $pago) {
            $metodo = $metodos->get($pago['payment_method_id']);

            $this->journal->record(
                branchId: $event->branchId,
                type: FinancialMovementType::Payment,
                amount: $this->entrada($pago),
                sourceType: $origenPago,
                sourceUlid: $pago['ulid'],
                actorMembershipId: $pago['charged_by_membership_id'],
                posSessionId: $event->posSessionId,
                paymentMethod: $metodo,
                occurredAt: $ocurrio,
            );

            if (Decimal::round($pago['change_amount'], 2) !== '0.00') {
                $this->journal->record(
                    branchId: $event->branchId,
                    type: FinancialMovementType::Change,
                    // CON SIGNO: el cambio sale del cajón. El diario lo exige desde el paso 10, después de que yo
                    // mismo lo asentara en positivo aquí — un cajón que cuadra al revés y nada que falle.
                    amount: bcmul($pago['change_amount'], '-1', 2),
                    sourceType: $origenPago,
                    sourceUlid: $pago['ulid'],
                    actorMembershipId: $pago['charged_by_membership_id'],
                    posSessionId: $event->posSessionId,
                    paymentMethod: $metodo,
                    occurredAt: $ocurrio,
                );
            }

            if (Decimal::round($pago['tip_amount'], 2) !== '0.00') {
                // El actor es a quien se le ATRIBUYE la propina, no quien cobró: es lo que permite que la liquidación
                // del paso 18 agrupe por persona directamente del diario, sin volver a leer los pagos.
                $this->journal->record(
                    branchId: $event->branchId,
                    type: FinancialMovementType::Tip,
                    amount: $pago['tip_amount'],
                    sourceType: $origenPago,
                    sourceUlid: $pago['ulid'],
                    actorMembershipId: $pago['tip_membership_id'] ?? $pago['charged_by_membership_id'],
                    posSessionId: $event->posSessionId,
                    paymentMethod: $metodo,
                    occurredAt: $ocurrio,
                );
            }
        }
    }
}
