<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Enums;

/**
 * Los estados de un pedido de la tienda en línea (Iteración 8, Tanda D, D51).
 *
 * ## Una máquina de estados explícita, no una cadena suelta
 *
 * Hasta la Tanda C el estado era una cadena con cuatro valores. La bandeja de aceptación necesita una máquina de verdad:
 * un pedido no puede saltar de `paid` a `completed` sin pasar por `accepted`, y un pedido `rejected` es terminal. Las
 * transiciones legales las declara {@see allowedNext()} y las hace cumplir `Order::transitionTo()`; escribirlas aquí, en
 * un solo sitio, es lo que impide que dos endpoints inventen caminos distintos.
 *
 * ## El ciclo
 *
 *   pending_payment → paid → accepted → ready → completed        (preparación / A&B)
 *          │            │        │    ↘ packed → shipped → completed   (envío / retail, ADR-013)
 *          ├→ failed    ├→ rejected (reembolso, D2)
 *          └→ cancelled └→ cancelled (D2)
 *
 * `preparing` se pliega en `accepted` para v1: la cocina ya está cocinando; la granularidad fina la llevan las comandas,
 * no el pedido.
 *
 * ## Dos caminos tras `accepted` (ADR-013)
 *
 * El MODO de la tienda decide el camino, pero el enum sólo declara qué es LEGAL: en preparación,
 * `accepted → ready → completed` (listo para recoger/entregar); en envío, `accepted → packed → shipped →
 * completed` (empacado → enviado → entregado). Los dos terminan en `completed`. El enum no conoce el modo:
 * la bandeja ofrece el camino según la tienda, y el candado de transiciones cubre ambos.
 */
enum OnlineOrderStatus: string
{
    /** Recién colocado, esperando el cobro por la pasarela. */
    case PendingPayment = 'pending_payment';

    /** Cobrado. Espera en la bandeja a que el negocio lo acepte (o se auto-acepta, D51). */
    case Paid = 'paid';

    /** El cobro no prosperó. Terminal. */
    case Failed = 'failed';

    /** Aceptado: el negocio se comprometió a prepararlo. Aquí se descuenta el inventario y se generan las comandas. */
    case Accepted = 'accepted';

    /** Listo para recoger o entregar (modo preparación). */
    case Ready = 'ready';

    /** Empacado, listo para enviar (modo envío, ADR-013). */
    case Packed = 'packed';

    /** Enviado: en camino al cliente (modo envío; fija `shipped_at`, admite paquetería y guía). */
    case Shipped = 'shipped';

    /** Entregado o recogido. Terminal. */
    case Completed = 'completed';

    /** Rechazado por el negocio: se reembolsa (D2). Terminal. */
    case Rejected = 'rejected';

    /** Cancelado. Terminal. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pendiente de pago',
            self::Paid => 'Pagado',
            self::Failed => 'Pago fallido',
            self::Accepted => 'Aceptado',
            self::Ready => 'Listo',
            self::Packed => 'Empacado',
            self::Shipped => 'Enviado',
            self::Completed => 'Completado',
            self::Rejected => 'Rechazado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Los estados a los que este puede pasar. Un estado terminal devuelve una lista vacía.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Failed, self::Cancelled],
            self::Paid => [self::Accepted, self::Rejected, self::Cancelled],
            // Tras aceptar, el camino depende del modo de la tienda: `ready` (preparación) o `packed` (envío).
            self::Accepted => [self::Ready, self::Packed, self::Cancelled],
            self::Ready => [self::Completed],
            self::Packed => [self::Shipped],
            self::Shipped => [self::Completed],
            self::Failed, self::Completed, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), strict: true);
    }
}
