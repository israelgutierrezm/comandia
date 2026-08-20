<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Pos\Domain\Enums\PosDiscountKind;
use App\Modules\Pos\Domain\Exceptions\DiscountRequiresAuthorizationException;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosDiscount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Events\PosDiscountApplied;
use App\Modules\Shared\Domain\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Descontar y regalar (§6.3, «zona de máxima auditoría»).
 *
 * ## El monto SIEMPRE lo calcula el servidor
 *
 * El cliente manda el tipo y el valor —«10 %», «50 pesos», «cortesía»— y nunca el resultado. Si mandara el monto, un
 * «10 %» podría llegar como cualquier cifra desde la consola del navegador, y el descuento es justamente donde eso
 * importa: es la vía más común de sacar dinero de un restaurante sin que parezca robo.
 *
 * ## El PIN se pide SIEMPRE, incluso a quien tiene el permiso
 *
 * No es desconfianza hacia el gerente: el permiso lo tiene la **sesión** —una terminal abierta que cualquiera puede
 * tocar mientras su dueño atiende una mesa— y el PIN lo tiene la **persona**. Se registran los dos, y que sean columnas
 * distintas es el punto entero: el patrón que un reporte de robo hormiga busca es «el mismo mesero pidiendo
 * autorización veinte veces por turno».
 *
 * ## Una cortesía NO es un descuento del 100 %
 *
 * Lo es aritméticamente y no operativamente. Se cuenta aparte —«cuánto regalé» y «cuánto descontué» son dos preguntas—
 * y marca la línea con `is_courtesy`, de donde sale que una cortesía **sí descuenta inventario** (§6.3): el plato se
 * preparó y los insumos se gastaron, aunque no se haya cobrado.
 *
 * ## Y no se descuenta una cuenta ya pagada
 *
 * El dinero ya entró. Corregir de más se hace con una reversa de pago, no descontando después — que es exactamente la
 * maniobra que §6.3 quiere impedir: cobrar el total, descontar luego y quedarse la diferencia.
 */
final readonly class ApplyDiscount
{
    public function __construct(
        private ContextHolder $context,
        private PinAuthorizationService $pin,
        private CaptureOrderItems $items,
        private AuditLogger $audit,
        private ResolveOpenSession $sessions,
    ) {}

    /**
     * Aplica un descuento a la cuenta entera o a una de sus líneas.
     *
     * @throws DiscountRequiresAuthorizationException
     */
    public function apply(
        PosAccount $account,
        PosDiscountKind $kind,
        string $value,
        string $reason,
        ?string $itemUlid,
        ?string $authorizationToken,
    ): PosAccount {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw PosAccountException::membershipRequired());

        if ($kind->requiresItem() && $itemUlid === null) {
            throw PosAccountException::courtesyRequiresItem();
        }

        if (! $account->status->acceptsDiscounts()) {
            throw PosAccountException::accountNotDiscountable(
                $account->displayName(),
                $account->status->label(),
            );
        }

        // Exige caja abierta, como el cobro (D252). Un descuento es dinero que se dejó de cobrar y el corte tiene que
        // poder explicarlo: sin turno, el asiento del diario no tendría a qué arqueo pertenecer.
        $session = $this->sessions->forBranch((int) $account->branch_id);

        if ($authorizationToken === null) {
            throw DiscountRequiresAuthorizationException::forKind($kind, $itemUlid === null);
        }

        $permiso = DiscountRequiresAuthorizationException::forKind($kind, $itemUlid === null)->requiredPermission();

        // Gasta la concesión y devuelve al AUTORIZADOR. Una autorización no sirve para dos descuentos: sin eso, un PIN
        // pedido una vez descontaría toda la noche.
        $autorizador = $this->pin->consume($authorizationToken, $permiso);

        return DB::transaction(function () use ($account, $kind, $value, $reason, $itemUlid, $actor, $autorizador, $session): PosAccount {
            $cuenta = PosAccount::query()->whereKey($account->id)->lockForUpdate()->sole();

            $item = $itemUlid === null ? null : $this->itemOf($cuenta, $itemUlid);

            $base = $item !== null
                ? $this->itemBase($item)
                : $this->accountBase($cuenta);

            $monto = $this->resultingAmount($kind, $value, $base);

            $descuento = PosDiscount::create([
                'pos_account_id' => $cuenta->id,
                'pos_order_item_id' => $item?->id,
                'kind' => $kind,
                'value' => Decimal::round($value, 2),
                'resulting_amount' => $monto,
                'reason' => trim($reason),
                'applied_by_membership_id' => $actor,
                'authorized_by_membership_id' => $autorizador->id,
            ]);

            // Un descuento de ITEM se escribe en la línea, porque `line_total` es una columna generada que ya lo resta.
            // Uno de CUENTA no tiene línea donde vivir y lo resta el recálculo.
            if ($item !== null) {
                $item->update([
                    'discount_amount' => bcadd((string) $item->discount_amount, $monto, 2),
                    'is_courtesy' => $kind === PosDiscountKind::Courtesy ? true : $item->is_courtesy,
                ]);
            }

            $cuenta = $this->items->recalculate($cuenta);

            $this->audit->log(
                action: AuditAction::POS_DISCOUNT_APPLIED,
                auditable: $cuenta,
                after: [
                    'folio' => $cuenta->folioNumber(),
                    'kind' => $kind->value,
                    'value' => $descuento->value,
                    'resulting_amount' => $monto,
                    'reason' => trim($reason),
                    'item' => $item?->article_name,
                    'applied_by_membership_id' => $actor,
                    'authorized_by_membership_id' => $autorizador->id,
                ],
            );

            DB::afterCommit(function () use ($cuenta, $descuento, $kind, $monto, $actor, $autorizador, $reason, $session): void {
                PosDiscountApplied::dispatch(
                    (int) $cuenta->tenant_id,
                    (int) $cuenta->branch_id,
                    (string) $cuenta->ulid,
                    (string) $descuento->ulid,
                    $kind->value,
                    $monto,
                    trim($reason),
                    (int) $session->id,
                    $actor,
                    (int) $autorizador->id,
                    $descuento->created_at?->toIso8601String() ?? now()->toIso8601String(),
                );
            });

            return $cuenta;
        });
    }

    /**
     * Cuánto cuesta este descuento.
     *
     * @return numeric-string
     */
    private function resultingAmount(PosDiscountKind $kind, string $value, string $base): string
    {
        $monto = match ($kind) {
            // Una cortesía es la línea entera: no se pide valor, se toma lo que vale.
            PosDiscountKind::Courtesy => $base,

            // A seis decimales y se redondea al final, como toda reducción de escala del proyecto (D237).
            PosDiscountKind::Percentage => Decimal::round(
                bcdiv(bcmul($base, Decimal::round($value, 2), 6), '100', 6),
                2,
            ),

            PosDiscountKind::Amount => Decimal::round($value, 2),
        };

        if (bccomp($monto, '0', 2) <= 0) {
            throw PosAccountException::discountNotPositive();
        }

        // Nunca más que la base. Un descuento de 500 sobre una línea de 45 dejaría un total negativo —el negocio
        // pagándole al cliente— y el CHECK de la base ni siquiera lo vería, porque el monto en sí es positivo.
        if (bccomp($monto, $base, 2) > 0) {
            throw PosAccountException::discountAboveBase($monto, $base);
        }

        return $monto;
    }

    /**
     * Lo que vale la línea hoy, ya descontado lo que llevara.
     *
     * Se usa la base VIVA y no el importe original a propósito: dos descuentos del 50 % sobre la misma línea dejan un
     * 25 % del precio, no cero. Es lo que espera quien opera —«otro 50 % encima»— y es lo que impide que dos descuentos
     * sumen más que la línea.
     *
     * @return numeric-string
     */
    private function itemBase(PosOrderItem $item): string
    {
        return (string) $item->line_total;
    }

    /**
     * Lo que queda por cobrar de la cuenta.
     *
     * @return numeric-string
     */
    private function accountBase(PosAccount $account): string
    {
        return (string) $account->total;
    }

    private function itemOf(PosAccount $account, string $ulid): PosOrderItem
    {
        $item = PosOrderItem::query()
            ->where('pos_account_id', $account->id)
            ->where('ulid', $ulid)
            ->lockForUpdate()
            ->first();

        if ($item === null) {
            throw PosAccountException::itemsNotInAccount($account->displayName());
        }

        if (! $item->isBillable()) {
            throw PosAccountException::itemAlreadyCancelled((string) $item->article_name);
        }

        return $item;
    }
}
