<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain;

/**
 * Cuánto espera un pedido en línea su pago (D380).
 *
 * El enlace de la pasarela vence a los 30 minutos y el pedido se da por vencido a los 45: los quince de diferencia son
 * para el aviso de un pago hecho en el último minuto, que puede tardar en llegar. Lo que llegue después se reembolsa.
 */
final class UnpaidOrderPolicy
{
    /** Cuánto vive el enlace de pago que crea la pasarela. */
    public const PAYMENT_LINK_MINUTES = 30;

    /** A partir de cuándo un pedido sin pago pasa a «Pago vencido». */
    public const EXPIRE_AFTER_MINUTES = 45;
}
