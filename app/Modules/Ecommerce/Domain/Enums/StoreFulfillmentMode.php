<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Enums;

/**
 * El modo de operación de la tienda en línea (ADR-013).
 *
 * - **preparación** — A&B: al aceptar un pedido se genera comanda a cocina y la entrega es inmediata
 *   (pickup o entrega local). Es el comportamiento histórico de la tienda.
 * - **envío** (`dispatch`) — retail: el pedido se acepta, se empaca y se envía después, SIN comanda de
 *   cocina. Es lo que habilita a un giro no-A&B (p. ej. una ferretería) a vender en línea.
 *
 * No se llama `shipping` para no confundirlo con `delivery_type` (que es por pedido: pickup|shipping).
 */
enum StoreFulfillmentMode: string
{
    case Preparation = 'preparation';

    case Dispatch = 'dispatch';

    public function label(): string
    {
        return match ($this) {
            self::Preparation => 'Preparación (alimentos y bebidas)',
            self::Dispatch => 'Envío (se empaca y se envía después)',
        };
    }

    /** En modo envío no hay cocina: el pedido no genera comanda y el fulfillment es empacar → enviar. */
    public function isDispatch(): bool
    {
        return $this === self::Dispatch;
    }
}
