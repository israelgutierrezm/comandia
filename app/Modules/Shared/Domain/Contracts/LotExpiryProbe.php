<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * «¿Este código de lote es de una partida que ya caducó?»
 *
 * Una recepción reusa el lote que ya existe con el mismo código (D208): la misma partida puede llegar en dos facturas. Si
 * esa partida ya caducó, la mercancía nueva entraba a un lote del que no se surte —la salida automática lo salta— y se
 * quedaba atorada. `Purchasing` lo pregunta al confirmar, por esta sonda, sin conocer a `Inventory`, dueño de los lotes
 * (D377).
 *
 * El null-object responde `false`: sin `Inventory` no hay lotes que consultar, y una recepción no se bloquea por un fallo
 * de infraestructura.
 */
interface LotExpiryProbe
{
    /**
     * ¿El lote `$code` del artículo existe y ya caducó (marcado «Caducado», o con su caducidad vencida)?
     */
    public function isExpired(int $articleId, string $code): bool;
}
