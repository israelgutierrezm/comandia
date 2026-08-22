<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Customers\Infrastructure\Models\CustomerFiscalProfile;
use App\Modules\Finance\Domain\Enums\PaymentMethodKind;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Pos\Domain\Enums\PosAccountStatus;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosPayment;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Events\PosAccountPaid;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Modules\Shared\Application\Authorization\Authorize;

/**
 * Cobrar una cuenta (§6.3).
 *
 * ## Sin sesión de caja abierta NO hay cobro
 *
 * §6.3 lo dice y es la mitad de por qué el corte puede existir: un pago que no pertenece a ningún turno es dinero que
 * entró y que ningún arqueo puede explicar. Abrir la cuenta sí se puede sin caja —el mesero toma la orden antes de que
 * el cajero llegue (paso 7)— pero cobrarla no.
 *
 * ## El cambio se calcula AQUÍ y se guarda
 *
 * `entregado − aplicado`, sólo para métodos que dan cambio. Se guarda porque es un hecho: el cajón ya se abrió con esa
 * cifra. Recalcularlo después de un descuento o de una reversa daría otro número y el cajón no lo sabría.
 *
 * Y la propina **no** entra en el cálculo del cambio: si el cliente deja mil por una cuenta de 850 con 50 de propina,
 * su cambio son 100, no 150. Es el error más caro de este servicio, porque lo cometería a favor del cliente y en contra
 * del cajero, todas las noches.
 *
 * ## La cuenta se cierra sola cuando queda cubierta
 *
 * `paid_total >= total` la pasa a `paid`. No hay un botón aparte de «marcar pagada»: sería un estado que alguien podría
 * poner sin que hubiera dinero, y todo el resto del sistema —el corte, la mesa, el inventario— cuelga de ese estado.
 */
final readonly class ChargeAccount
{
    private const DOCUMENT_TYPE = 'pos_receipt';

    private const SERIES = 'T';

    public function __construct(
        private ContextHolder $context,
        private DocumentNumberAllocator $folios,
        private CaptureOrderItems $items,
        private AccountWorkflow $accounts,
        private ResolveOpenSession $sessions,
        private ChargeToCustomerCredit $credit,
        private Authorize $authorize,
        private ApplyPromotions $promotions,
    ) {}

    /**
     * Aplica una o varias líneas de pago.
     *
     * @param  list<array{payment_method_ulid: string, amount: numeric-string, tendered_amount?: numeric-string|null, tip_amount?: numeric-string|null, tip_membership_ulid?: string|null, reference?: string|null, authorization_token?: string|null}>  $lines
     */
    public function charge(PosAccount $account, array $lines, ?string $fiscalProfileUlid = null): PosAccount
    {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw PosAccountException::membershipRequired());

        $session = $this->sessions->forBranch((int) $account->branch_id);

        return DB::transaction(function () use ($account, $lines, $actor, $session, $fiscalProfileUlid): PosAccount {
            $cuenta = PosAccount::query()->whereKey($account->id)->with('restaurantTable')->lockForUpdate()->sole();

            $this->assertChargeable($cuenta);

            $ahora = CarbonImmutable::now();

            // El snapshot fiscal, si el cliente pidió factura: se resuelve y se valida ANTES de tocar dinero, para que
            // un perfil inválido rechace el cobro entero en lugar de dejar la venta sin sus datos fiscales.
            $fiscal = $this->resolveFiscalSnapshot($cuenta, $fiscalProfileUlid);

            // Las promociones se materializan AQUÍ, una sola vez, antes de calcular lo que se debe: el resolver del
            // kernel decide cuáles aplican y esto graba su efecto en `pos_discounts`, reduciendo el total. Es idempotente
            // —una división cobrada en dos pagos no re-aplica— y si el módulo Promotions falta, el resolver es el
            // null-object y el cobro sigue sin promoción (§6, el POS nunca se bloquea).
            $cuenta = $this->promotions->materialize($cuenta, $actor, $session, $ahora);

            foreach ($lines as $linea) {
                $this->applyLine($cuenta, $linea, $actor, $session, $ahora);
            }

            return $this->settle($cuenta, $actor, $ahora, $session, $fiscal);
        });
    }

    /**
     * El snapshot fiscal del perfil elegido, congelado para el ticket facturable (D317).
     *
     * @return array<string, string>|null
     */
    private function resolveFiscalSnapshot(PosAccount $account, ?string $fiscalProfileUlid): ?array
    {
        if ($fiscalProfileUlid === null) {
            return null;
        }

        if ($account->customer_id === null) {
            throw PosAccountException::fiscalProfileWithoutCustomer();
        }

        $profile = CustomerFiscalProfile::query()
            ->where('ulid', $fiscalProfileUlid)
            ->where('customer_id', $account->customer_id)
            ->first();

        if ($profile === null) {
            throw PosAccountException::fiscalProfileNotFound();
        }

        return [
            'fiscal_rfc' => (string) $profile->rfc,
            'fiscal_business_name' => (string) $profile->business_name,
            'fiscal_postal_code' => (string) $profile->postal_code,
            'fiscal_tax_regime_code' => (string) $profile->tax_regime_code,
            'fiscal_cfdi_use_code' => (string) $profile->cfdi_use_code,
        ];
    }

    /**
     * Una línea de pago, con su cambio y su propina congelados.
     *
     * @param  array{payment_method_ulid: string, amount: numeric-string, tendered_amount?: numeric-string|null, tip_amount?: numeric-string|null, tip_membership_ulid?: string|null, reference?: string|null, authorization_token?: string|null}  $linea
     */
    private function applyLine(
        PosAccount $account,
        array $linea,
        int $actor,
        PosSession $session,
        CarbonImmutable $ahora,
    ): void {
        $metodo = PaymentMethod::query()->where('ulid', $linea['payment_method_ulid'])->sole();

        if (! $metodo->isActive()) {
            throw PosAccountException::paymentMethodInactive((string) $metodo->name);
        }

        if ($metodo->requires_reference && blank($linea['reference'] ?? null)) {
            throw PosAccountException::paymentReferenceRequired((string) $metodo->name);
        }

        $monto = Decimal::round($linea['amount'], 2);
        $propina = Decimal::round($linea['tip_amount'] ?? '0', 2);

        $entregado = isset($linea['tendered_amount']) && $linea['tendered_amount'] !== null
            ? Decimal::round((string) $linea['tendered_amount'], 2)
            : null;

        $cambio = $this->change($metodo, $monto, $propina, $entregado);

        // COBRAR A CRÉDITO: se carga el saldo del cliente en esta misma transacción, antes de escribir el pago.
        //
        // Síncrono y no por evento a propósito. Si el cargo se hiciera después del commit, una cuenta podría quedar
        // pagada con el saldo del cliente sin cargar: el negocio habría regalado la comida y el estado de cuenta no lo
        // sabría. Lo que sí va por evento es el asiento del diario, que si falla se repara re-despachando.
        if ($metodo->kind === PaymentMethodKind::CustomerCredit) {
            // FIAR EXIGE SU PROPIO PERMISO (D296).
            //
            // Estaba declarado en el catálogo y asignado a las plantillas de rol desde la Iteración 1, y no lo
            // comprobaba nadie: fiar exigía sólo poder cobrar. Un permiso que se puede otorgar y no hace nada es peor
            // que uno que falta — un negocio que revisa sus roles y lo ve desmarcado en el mesero cree haberlo
            // impedido.
            //
            // Es distinto de rebasar el límite, que pide PIN con `finance.customer_credit.manage`: aquél autoriza una
            // excepción, éste autoriza la operación normal.
            $this->authorize->authorizeWrite('pos.credit.charge_to_customer', (int) $account->branch_id);

            $this->credit->charge(
                account: $account,
                amount: bcadd($monto, $propina, 2),
                session: $session,
                actorMembershipId: $actor,
                authorizationToken: $linea['authorization_token'] ?? null,
            );
        }

        PosPayment::create([
            'branch_id' => $account->branch_id,
            'pos_account_id' => $account->id,
            'pos_session_id' => $session->id,
            'payment_method_id' => $metodo->id,
            'amount' => $monto,
            'tendered_amount' => $entregado,
            'change_amount' => $cambio,
            'tip_amount' => $propina,

            // A quién se le atribuye, CONGELADO aquí (D233). Por omisión el titular de la cuenta, que es quien la
            // atendió — no quien tocó la pantalla para cobrar.
            'tip_membership_id' => bccomp($propina, '0', 2) === 0
                ? null
                : $this->tipRecipient($account, $linea),

            'reference' => $linea['reference'] ?? null,
            'charged_by_membership_id' => $actor,
            'occurred_at' => $ahora,
        ]);
    }

    /**
     * El cambio de esta línea.
     *
     * Sólo si el método lo permite y sólo si el cliente entregó de más. La **propina no cuenta**: mil pesos por una
     * cuenta de 850 con 50 de propina devuelven 100, no 150.
     *
     * @return numeric-string
     */
    private function change(PaymentMethod $metodo, string $monto, string $propina, ?string $entregado): string
    {
        if (! $metodo->allows_change || $entregado === null) {
            return '0.00';
        }

        $aCubrir = bcadd($monto, $propina, 2);

        if (bccomp($entregado, $aCubrir, 2) < 0) {
            throw PosAccountException::tenderedBelowAmount($entregado, $aCubrir);
        }

        return bcsub($entregado, $aCubrir, 2);
    }

    /**
     * A quién se le atribuye la propina de esta línea.
     */
    private function tipRecipient(PosAccount $account, array $linea): ?int
    {
        if (! blank($linea['tip_membership_ulid'] ?? null)) {
            return (int) \App\Modules\Identity\Infrastructure\Models\TenantMembership::query()
                ->where('ulid', $linea['tip_membership_ulid'])
                ->sole()
                ->id;
        }

        // El titular, y si la cuenta no tiene, quien la abrió. Nunca `null` con propina de por medio: una propina sin
        // dueño es dinero que la liquidación del paso 18 no sabría a quién dar.
        return (int) ($account->waiter_membership_id ?? $account->opened_by_membership_id);
    }

    /**
     * Recalcula lo pagado y cierra la cuenta si quedó cubierta.
     */
    /**
     * @param  array<string, string>|null  $fiscal
     */
    private function settle(PosAccount $account, int $actor, CarbonImmutable $ahora, PosSession $session, ?array $fiscal = null): PosAccount
    {
        $pagos = PosPayment::query()->where('pos_account_id', $account->id)->get();

        $pagado = '0.00';
        $propinas = '0.00';
        $cambios = '0.00';

        foreach ($pagos as $pago) {
            $pagado = bcadd($pagado, (string) $pago->amount, 2);
            $propinas = bcadd($propinas, (string) $pago->tip_amount, 2);
            $cambios = bcadd($cambios, (string) $pago->change_amount, 2);
        }

        $cubierta = bccomp($pagado, (string) $account->total, 2) >= 0;

        $account->update([
            // La cuenta queda atada a la CAJA en la que se cobró. Se me había olvidado, y la FK del diario lo destapó
            // con un `pos_session_id = 0`: sin esto, una cuenta pagada no pertenece a ningún turno y el corte no puede
            // atribuirle la venta. Una columna nullable lo habría dejado pasar en silencio.
            'pos_session_id' => $account->pos_session_id ?? $session->id,

            'paid_total' => $pagado,
            'tip_total' => $propinas,
            'change_total' => $cambios,
            'status' => $cubierta ? PosAccountStatus::Paid : $account->status,
            'paid_at' => $cubierta ? $ahora : null,
        ]);

        $this->items->touchVersion($account);

        $account->refresh();

        if ($cubierta) {
            // La mesa se libera AQUÍ y no por evento, aunque la tabla de §7 del diseño listara a `Floor` entre los
            // oyentes de `PosAccountPaid`. Es la misma razón de D239: el estado de una mesa tiene que ser inmediato y
            // estar en la transacción, porque la pantalla de piso decide sobre él y «¿queda alguna cuenta viva en esta
            // mesa?» es una pregunta sobre CUENTAS, que es lo que este módulo sabe contestar.
            //
            // Qué significa liberar —libre o por limpiar, y qué pasa con la unión— lo sigue decidiendo `Floor` a través
            // de `TableOccupancy`. La frontera se respeta; lo que cambia es que la llamada es directa y no diferida.
            $this->accounts->releaseTableIfEmpty($account);

            $this->emitFinalReceipt($account, $actor, $ahora, $pagado, $propinas, $cambios, $pagos, $fiscal);

            // Si esto era una PARTE de una cuenta dividida, la madre queda pagada cuando todas sus partes lo están.
            //
            // La madre no emite su propio ticket ni su propio evento: el dinero ya se asentó parte por parte, y volver a
            // asentar su total contaría la venta dos veces. Lo único que le falta es su estado y su mesa.
            $this->settleParentIfComplete($account, $ahora);
        }

        return $account;
    }

    /**
     * Cierra la cuenta madre cuando todas sus partes están pagadas.
     *
     * La suma de las partes es exactamente el total de la madre —el reparto carga el resto a la primera parte, ver
     * `AccountOperations::shares()`— así que basta con que no quede ninguna sin pagar.
     */
    private function settleParentIfComplete(PosAccount $account, CarbonImmutable $ahora): void
    {
        if (! $account->isSplitPart()) {
            return;
        }

        $madre = PosAccount::query()->whereKey($account->parent_account_id)->lockForUpdate()->first();

        if ($madre === null) {
            return;
        }

        $pendientes = PosAccount::query()
            ->where('parent_account_id', $madre->id)
            ->where('status', '!=', PosAccountStatus::Paid->value)
            ->exists();

        if ($pendientes) {
            return;
        }

        $madre->update([
            'status' => PosAccountStatus::Paid,
            'paid_at' => $ahora,
        ]);

        $this->accounts->releaseTableIfEmpty($madre->refresh());
    }

    /**
     * El ticket final: el único papel del POS que FOLIA.
     *
     * Será el folio facturable (ADR-005), así que se asigna con el asignador bajo lock y sin huecos. Una comanda no
     * folia porque es un papel de cocina; esto es el comprobante de una venta.
     *
     * El evento va **después del commit**: `Finance` asienta los pagos, la propina y el cambio; `Printing` saca el
     * papel; `Floor` libera la mesa; e `Inventory` descuenta en cola (§6.2). Ninguno puede tumbar el cobro — el dinero
     * ya entró, y un fallo posterior no puede deshacerlo.
     */
    /**
     * @param  array<string, string>|null  $fiscal
     */
    private function emitFinalReceipt(
        PosAccount $account,
        int $actor,
        CarbonImmutable $ahora,
        string $pagado,
        string $propinas,
        string $cambios,
        $pagos,
        ?array $fiscal = null,
    ): void {
        $folio = $this->folios->next((int) $account->branch_id, self::DOCUMENT_TYPE, self::SERIES);

        $ticket = PosTicket::create([
            'branch_id' => $account->branch_id,
            'kind' => PosTicketKind::FinalReceipt,
            'pos_account_id' => $account->id,
            'series' => self::SERIES,
            'folio' => $folio,
            'issued_by_membership_id' => $actor,
            'issued_at' => $ahora,

            // El snapshot fiscal, congelado, si se pidió factura. Null = público en general (el caso normal).
            ...($fiscal ?? []),
        ]);

        // Las líneas viajan EN el evento, en primitivos. `Finance` no puede leer `pos_payments`: `Pos` ya depende de
        // `Finance` desde el paso 6, así que la lectura al revés cerraría un ciclo entre el punto de venta y el dinero.
        $lineas = $pagos->map(fn (PosPayment $p): array => [
            'ulid' => (string) $p->ulid,
            'payment_method_id' => (int) $p->payment_method_id,
            'amount' => (string) $p->amount,
            'change_amount' => (string) $p->change_amount,
            'tip_amount' => (string) $p->tip_amount,
            'tip_membership_id' => $p->tip_membership_id === null ? null : (int) $p->tip_membership_id,
            'charged_by_membership_id' => (int) $p->charged_by_membership_id,
        ])->values()->all();

        // Lo vendido, para el descuento de inventario. Las CORTESÍAS van incluidas —el plato se preparó y los insumos
        // se gastaron aunque no se cobrara (§6.3)— y los cancelados no, porque el scope `billable()` los deja fuera.
        $vendido = PosOrderItem::query()
            ->where('pos_account_id', $account->id)
            ->billable()
            ->get()
            ->map(fn (PosOrderItem $i): array => [
                'item_ulid' => (string) $i->ulid,
                'article_id' => (int) $i->article_id,
                'quantity' => (string) $i->quantity,
                'preparation_area_id' => $i->preparation_area_id === null ? null : (int) $i->preparation_area_id,
                'is_courtesy' => (bool) $i->is_courtesy,
            ])->values()->all();

        DB::afterCommit(function () use ($account, $ticket, $actor, $ahora, $pagado, $propinas, $cambios, $lineas, $vendido): void {
            PosAccountPaid::dispatch(
                (int) $account->tenant_id,
                (int) $account->branch_id,
                (string) $account->ulid,
                $account->displayName(),
                (int) $account->pos_session_id,
                (string) $ticket->ulid,
                (string) $account->total,
                $pagado,
                $propinas,
                $cambios,
                $lineas,
                $vendido,
                $actor,
                $ahora->toIso8601String(),
            );
        });
    }

    private function assertChargeable(PosAccount $account): void
    {
        if (! $account->status->acceptsPayments()) {
            throw PosAccountException::accountNotChargeable(
                $account->displayName(),
                $account->status->label(),
            );
        }
    }
}
