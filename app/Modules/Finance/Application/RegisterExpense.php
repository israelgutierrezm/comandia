<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Finance\Domain\Enums\ExpenseSource;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Domain\Exceptions\ExpenseInvariantException;
use App\Modules\Finance\Domain\Exceptions\ExpenseRequiresAuthorizationException;
use App\Modules\Finance\Infrastructure\Models\Expense;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Registrar un gasto (§6.5).
 *
 * ## El asiento del diario va DENTRO de la transacción, y eso se desvía del diseño
 *
 * §7.2 listaba un evento `ExpenseRegistered` emitido por `Finance` y escuchado por `Finance`. Un evento para un efecto
 * **dentro del mismo módulo** no compra nada: no cruza ninguna frontera, no permite que nadie más reaccione sin
 * conocernos, y sí añade un salto en el que el asiento puede perderse.
 *
 * Y aquí la atomicidad no sólo es posible, es lo correcto. Los eventos del POS corren después del commit porque un fallo
 * al asentar **no puede tumbar un cobro** (D220): el dinero ya entró. Un gasto es distinto — es una operación de
 * finanzas de principio a fin, y un gasto registrado sin su asiento sería dinero que salió y que el corte no conoce.
 * Justo lo que este registro existe para evitar.
 *
 * Así que: una transacción, dos escrituras, o ninguna.
 *
 * ## El umbral, y por qué existe al revés que en el cajón
 *
 * Abrir el cajón pide PIN siempre (D248) y un gasto sólo por encima de un monto. No es incoherencia: si todo gasto
 * pidiera PIN, el cajero dejaría de registrar los 40 pesos de hielo para no ir a buscar al gerente, el dinero saldría
 * igual y el arqueo se descuadraría **sin rastro**. Con el cajón no hay ese riesgo, porque no registrar la apertura no
 * es una opción — el cajón se abre o no se abre.
 */
final readonly class RegisterExpense
{
    public function __construct(
        private ContextHolder $context,
        private Settings $settings,
        private PinAuthorizationService $pin,
        private RecordFinancialMovement $journal,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws ExpenseRequiresAuthorizationException
     */
    public function register(
        Branch $branch,
        ExpenseCategory $category,
        ExpenseSource $source,
        string $amount,
        string $description,
        ?int $posSessionId = null,
        ?PaymentMethod $method = null,
        ?string $receiptPath = null,
        ?string $authorizationToken = null,
    ): Expense {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw ExpenseInvariantException::membershipRequired());

        if (! $category->isActive()) {
            throw ExpenseInvariantException::inactiveCategory((string) $category->name);
        }

        $monto = Decimal::round($amount, 2);

        if (bccomp($monto, '0', 2) <= 0) {
            throw ExpenseInvariantException::notPositive();
        }

        $this->assertShape($source, $posSessionId, $method);

        $autorizador = $this->authorizeIfNeeded($branch, $monto, $authorizationToken);

        return DB::transaction(function () use (
            $branch, $category, $source, $monto, $description,
            $posSessionId, $method, $receiptPath, $actor, $autorizador
        ): Expense {
            $ahora = CarbonImmutable::now();

            $expense = Expense::create([
                'branch_id' => $branch->id,
                'expense_category_id' => $category->id,
                'source' => $source,
                'pos_session_id' => $posSessionId,
                'payment_method_id' => $method?->id,
                'amount' => $monto,
                'description' => trim($description),
                'receipt_path' => $receiptPath,
                'created_by_membership_id' => $actor,
                'authorized_by_membership_id' => $autorizador,
                'occurred_at' => $ahora,
            ])->refresh();

            // El asiento, en la misma transacción. En NEGATIVO: un gasto resta, y desde el paso 10 el diario rechaza el
            // signo equivocado (D253) en lugar de limitarse a advertirlo.
            //
            // ## El método de pago de un gasto DESDE CAJA es el efectivo
            //
            // Mi primera versión pasaba `null` razonando que «sin método, el diario decide por el tipo». Y el diario
            // decide que NO toca el cajón, porque `cashByNature()` no lista los gastos — con razón: un gasto puede salir
            // del cajón o de una transferencia, y el tipo solo no lo sabe.
            //
            // El resultado era un gasto de caja asentado como si no tocara el efectivo: el «esperado» del arqueo salía
            // 250 pesos más alto de lo que hay en el cajón, y la diferencia se le achacaría al cajero. Lo destapó la
            // prueba que comprueba `affects_cash_drawer`.
            //
            // La forma correcta es decir la verdad: un gasto desde caja **se pagó en efectivo**, así que lleva el método
            // de efectivo. No es un truco para encender la bandera — es lo que ocurrió.
            $this->journal->record(
                branchId: (int) $branch->id,
                type: FinancialMovementType::Expense,
                amount: bcmul($monto, '-1', 2),
                sourceType: Expense::class,
                sourceUlid: (string) $expense->ulid,
                actorMembershipId: $autorizador ?? $actor,
                posSessionId: $posSessionId,
                paymentMethod: $source === ExpenseSource::CashSession ? $this->cashMethod() : $method,
                occurredAt: $ahora,
            );

            $this->audit->log(
                action: AuditAction::EXPENSE_REGISTERED,
                auditable: $expense,
                after: [
                    'amount' => $monto,
                    'source' => $source->value,
                    'category' => $category->name,
                    'description' => trim($description),
                    'created_by_membership_id' => $actor,
                    'authorized_by_membership_id' => $autorizador,
                ],
            );

            return $expense;
        });
    }

    /**
     * El método de efectivo del negocio.
     *
     * Es uno de los cuatro que el alta siembra (D232) y su código es de sistema, así que existe siempre. Se busca por
     * `code` y no por `kind` porque un negocio puede tener varios métodos de tipo efectivo —«efectivo» y «efectivo
     * dólares»— y el del sistema es el que el arqueo cuenta.
     */
    private function cashMethod(): ?PaymentMethod
    {
        return PaymentMethod::query()->where('code', 'CASH')->first();
    }

    /**
     * Pide PIN si el monto pasa del umbral.
     *
     * @return int|null el autorizador, o `null` si no hizo falta
     */
    private function authorizeIfNeeded(Branch $branch, string $monto, ?string $token): ?int
    {
        $umbral = Decimal::round(
            (string) $this->settings->forBranch('finance.expense_authorization_threshold', (int) $branch->id),
            2,
        );

        // Por DEBAJO o igual, no hace falta. El «o igual» es deliberado: un umbral de 1000 significa «hasta mil sin
        // autorizar», que es como lo lee quien lo configura.
        if (bccomp($monto, $umbral, 2) <= 0) {
            return null;
        }

        if ($token === null) {
            throw ExpenseRequiresAuthorizationException::forAmount($monto, $umbral);
        }

        // Gasta la concesión: una autorización no sirve para dos gastos.
        return (int) $this->pin->consume($token, 'finance.expenses.authorize_above_threshold')->id;
    }

    /**
     * Un gasto de caja pertenece a un turno; uno de fuera lleva método de pago.
     *
     * La base tiene el mismo CHECK. Esto existe para que el error diga qué falta en lugar de salir como un fallo de
     * constraint.
     */
    private function assertShape(ExpenseSource $source, ?int $posSessionId, ?PaymentMethod $method): void
    {
        if ($source === ExpenseSource::CashSession && $posSessionId === null) {
            throw ExpenseInvariantException::cashExpenseNeedsSession();
        }

        if ($source === ExpenseSource::OutsideCash && $method === null) {
            throw ExpenseInvariantException::outsideExpenseNeedsMethod();
        }
    }
}
