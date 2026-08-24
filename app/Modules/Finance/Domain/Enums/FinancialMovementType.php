<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

/**
 * Los tipos del diario financiero (ADR-004, §6.5).
 *
 * ## Un catálogo cerrado, y por qué eso es la mitad del diseño
 *
 * ADR-004 exige que todo movimiento sea **tipado y con origen**. Un diario con un campo de texto libre para el concepto
 * es un diario que no se puede sumar: dos personas escriben «propina» y «propinas», y el corte de fin de mes deja de
 * cuadrar por una letra. El enum es lo que hace que el corte sea una consulta y no una interpretación.
 *
 * ## Cada tipo dice de qué lado está el dinero
 *
 * El signo lo lleva el monto, no el tipo — una reversa de pago es un pago negativo—, pero cada tipo tiene un sentido
 * natural, y `naturalSign()` lo declara para que quien asienta no lo tenga que recordar. Poner un retiro en positivo
 * dejaría el arqueo cuadrando al revés, y es el error más fácil de cometer.
 */
enum FinancialMovementType: string
{
    /**
     * El importe de una venta.
     *
     * NO es lo mismo que su pago: una cuenta de $200 pagada con $150 en efectivo y $50 con tarjeta produce **una**
     * venta y **dos** pagos. Separarlos es lo que permite preguntar «cuánto vendí» y «cómo me pagaron» sin que una
     * respuesta contamine la otra.
     */
    case Sale = 'sale';

    /**
     * El importe de una venta de la tienda en línea (Iteración 8, ADR-010).
     *
     * Es una `Sale` en todo salvo en una cosa que lo cambia todo para el arqueo: **no pasó por una caja**. El dinero
     * entró por la pasarela, no por el cajón, así que no pertenece a ninguna sesión ni a ningún turno. Reutilizar `Sale`
     * obligaría a relajar el invariante de §6.3 —«toda venta pertenece a una sesión»—, que es justo el candado que atrapa
     * una venta de mostrador sin turno. Un tipo propio deja ese candado intacto y, de paso, hace que «cuánto vendí en
     * línea» sea una consulta por tipo y no una interpretación del `source_type`. Suma como venta; no mueve el cajón.
     */
    case OnlineSale = 'online_sale';

    /** Una línea de pago aplicada a una cuenta. */
    case Payment = 'payment';

    /** El cambio devuelto. Sale del cajón, así que resta. */
    case Change = 'change';

    /** Propina cobrada, atribuida a alguien (D233). */
    case Tip = 'tip';

    /** Propina entregada a quien la ganó. Sale del cajón (D39). */
    case TipSettlement = 'tip_settlement';

    /** Descuento aplicado, por item o por cuenta. */
    case Discount = 'discount';

    /** Cortesía: venta en $0 que sí consume inventario (§6.3). */
    case Courtesy = 'courtesy';

    /**
     * Promoción automática aplicada (§6.3, D313).
     *
     * Separada de `Discount` a propósito: el reporte antifraude de §9 quiere distinguir lo que un humano autorizó con su
     * PIN (sospechoso) de lo que una regla del negocio aplicó sola. Ambos restan de lo vendido, pero no son la misma
     * pregunta.
     */
    case Promotion = 'promotion';

    /** Gasto. Afecta el arqueo sólo si salió de la caja, y eso lo dice `affects_cash_drawer`. */
    case Expense = 'expense';

    /** Retiro parcial de la caja durante el turno. */
    case Withdrawal = 'withdrawal';

    /** Depósito bancario del efectivo retirado (D38). */
    case Deposit = 'deposit';

    /** Crédito concedido: la cuenta quedó pagada y el cliente debe (§6.3). */
    case CreditGranted = 'credit_granted';

    /** Abono del cliente a su saldo. Entra a la caja al ocurrir (§6.3). */
    case CreditRepayment = 'credit_repayment';

    /** El fondo con el que se abre la caja. */
    case OpeningFloat = 'opening_float';

    /**
     * La diferencia del corte: lo declarado menos lo esperado.
     *
     * §6.5 lo exige por nombre —«Diferencia = movimiento tipado»— y es lo que hace que el diario cuadre consigo mismo.
     * Sin este tipo, un faltante de caja tendría que guardarse aparte o desaparecer, y las dos cosas son peores que
     * registrarlo.
     */
    case CountDifference = 'count_difference';

    /**
     * La corrección de otro movimiento, enlazada a él.
     *
     * Es el único mecanismo de corrección que admite un diario append-only (ADR-004): no se edita, se reversa. El
     * enlace `reverses_movement_id` es lo que permite reconstruir la historia — «esto se cobró y luego se devolvió» en
     * lugar de «esto nunca pasó».
     */
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venta',
            self::OnlineSale => 'Venta en línea',
            self::Payment => 'Pago',
            self::Change => 'Cambio',
            self::Tip => 'Propina',
            self::TipSettlement => 'Liquidación de propina',
            self::Discount => 'Descuento',
            self::Courtesy => 'Cortesía',
            self::Promotion => 'Promoción',
            self::Expense => 'Gasto',
            self::Withdrawal => 'Retiro',
            self::Deposit => 'Depósito',
            self::CreditGranted => 'Crédito concedido',
            self::CreditRepayment => 'Abono de crédito',
            self::OpeningFloat => 'Fondo de caja',
            self::CountDifference => 'Diferencia de corte',
            self::Reversal => 'Reversa',
        };
    }

    /**
     * El sentido natural del monto: `1` suma, `-1` resta, `0` no tiene sentido propio.
     *
     * Existe para que quien asienta no tenga que recordarlo. Los que devuelven `0` son los que dependen del caso: una
     * diferencia de corte puede ser sobrante o faltante, y una reversa hereda el signo contrario de lo que corrige.
     */
    public function naturalSign(): int
    {
        return match ($this) {
            self::Sale, self::OnlineSale, self::Payment, self::Tip, self::CreditRepayment, self::OpeningFloat => 1,
            self::Change, self::TipSettlement, self::Expense, self::Withdrawal, self::Deposit => -1,

            // El descuento, la cortesía y la promoción RESTAN de lo vendido: son el importe que no se cobró. Registrarlos
            // en positivo haría que descontar aumentara la venta, que es exactamente al revés.
            self::Discount, self::Courtesy, self::Promotion => -1,

            // El crédito concedido no mueve caja pero sí es dinero por cobrar: suma como derecho.
            self::CreditGranted => 1,

            self::CountDifference, self::Reversal => 0,
        };
    }

    /**
     * ¿Este tipo pertenece siempre a una sesión de caja?
     *
     * §6.3 dice que «toda venta, pago, retiro y cancelación pertenece a una sesión». Pero no todo lo del diario pasa
     * por una caja: un gasto pagado por transferencia desde la oficina y un depósito bancario existen sin turno abierto,
     * y una **venta en línea** cobra por pasarela sin que nadie abra un cajón (ADR-010). Distinguirlo aquí evita que el
     * arqueo exija una sesión donde no la hay.
     */
    public function requiresSession(): bool
    {
        return match ($this) {
            self::Sale, self::Payment, self::Change, self::Tip, self::Discount, self::Courtesy, self::Promotion,
            self::Withdrawal, self::OpeningFloat, self::CountDifference, self::CreditGranted => true,

            self::OnlineSale, self::Expense, self::Deposit, self::TipSettlement, self::CreditRepayment, self::Reversal => false,
        };
    }

    /**
     * ¿Este asiento debe registrar quién lo hizo?
     *
     * Todo movimiento del diario lleva a su responsable —es lo que lo hace auditable—, con una sola excepción: la **venta
     * en línea** (ADR-010), que es un asiento **automático**. La origina el cliente al pagar, no un miembro del personal,
     * así que no hay actor que registrar. La columna es nullable por esto; el candado lo exige aquí para todo lo demás.
     */
    public function requiresActor(): bool
    {
        return match ($this) {
            self::OnlineSale => false,

            self::Sale, self::Payment, self::Change, self::Tip, self::TipSettlement, self::Discount, self::Courtesy,
            self::Promotion, self::Expense, self::Withdrawal, self::Deposit, self::CreditGranted, self::CreditRepayment,
            self::OpeningFloat, self::CountDifference, self::Reversal => true,
        };
    }
}
