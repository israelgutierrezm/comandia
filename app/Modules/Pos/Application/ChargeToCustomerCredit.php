<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Customers\Application\RecordCreditMovement;
use App\Modules\Customers\Domain\Enums\CreditMovementType;
use App\Modules\Customers\Domain\Exceptions\CreditInvariantException;
use App\Modules\Customers\Domain\Exceptions\CreditLimitRequiresAuthorizationException;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerCredit;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Shared\Domain\Events\CustomerCreditGranted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fiar un consumo: cargar la cuenta al crédito de un cliente (§6.3, §8.3).
 *
 * ## Lo que esto MATA es la «cuenta que nunca se cierra»
 *
 * §6.3 la prohíbe, y el crédito es el mecanismo para el fiado. Sin él, un negocio que fía deja cuentas abiertas para
 * siempre —justo lo prohibido— y el corte de cada noche arrastra consumos de hace semanas. Con crédito, la cuenta queda
 * **pagada** y el fiado pasa a ser un saldo con nombre.
 *
 * ## Vive en `Pos` y no en `Customers`
 *
 * Porque es una operación del cobro: ocurre dentro de la transacción del pago y su condición de éxito es que la cuenta
 * quede pagada. `Customers` no debería conocer las cuentas del punto de venta — `Pos` es operaciones y `Customers` es
 * dominio, así que la flecha va hacia abajo y está declarada. Al revés sería una flecha hacia arriba que §2 prohíbe.
 *
 * Lo que sí es de `Customers` es **escribir el saldo**, y eso se hace por su única puerta.
 *
 * ## El límite se verifica AQUÍ y sobrepasarlo pide PIN
 *
 * 409 `authorization_required` y no 422: no hay nada que corregir en el formulario — el cliente, el monto y el método
 * son correctos. Lo que falta es que alguien decida fiarle de más, que es una decisión del negocio.
 */
final readonly class ChargeToCustomerCredit
{
    public function __construct(
        private RecordCreditMovement $credit,
        private PinAuthorizationService $pin,
    ) {}

    /**
     * @throws CreditLimitRequiresAuthorizationException
     */
    public function charge(
        PosAccount $account,
        string $amount,
        PosSession $session,
        int $actorMembershipId,
        ?string $authorizationToken = null,
    ): void {
        if ($account->customer_id === null) {
            throw PosAccountException::creditNeedsCustomer($account->displayName());
        }

        $customer = Customer::query()->whereKey($account->customer_id)->sole();

        $credito = CustomerCredit::query()
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->first()
            ?? throw CreditInvariantException::noCreditAccount((string) $customer->name);

        if (! $credito->is_enabled) {
            throw CreditInvariantException::creditDisabled((string) $customer->name);
        }

        // El límite. Pasarse necesita autorización, y la concesión se gasta: un PIN pedido una vez no fía toda la noche.
        if (! $credito->allows($amount)) {
            if ($authorizationToken === null) {
                throw CreditLimitRequiresAuthorizationException::forCustomer(
                    (string) $customer->name,
                    $credito->available(),
                    $amount,
                );
            }

            $this->pin->consume($authorizationToken, 'finance.customer_credit.manage');
        }

        $this->credit->record(
            customer: $customer,
            type: CreditMovementType::Charge,
            amount: $amount,
            actorMembershipId: $actorMembershipId,
            sourceType: PosAccount::class,
            sourceUlid: (string) $account->ulid,
            posSessionId: (int) $session->id,
        );

        $ahora = CarbonImmutable::now();

        // El asiento del diario va por evento porque SÍ puede llegar tarde: el saldo del cliente ya está cargado, que
        // es lo que no podía esperar.
        DB::afterCommit(function () use ($account, $customer, $amount, $session, $actorMembershipId, $ahora): void {
            CustomerCreditGranted::dispatch(
                (int) $account->tenant_id,
                (int) $account->branch_id,
                (string) $customer->ulid,
                (string) $account->ulid,
                $amount,
                (int) $session->id,
                $actorMembershipId,
                $ahora->toIso8601String(),
            );
        });
    }
}
