<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un pedido de la tienda en línea fue aceptado (Iteración 8, Tanda D, D51).
 *
 * La aceptación es el momento en que el negocio se compromete a preparar el pedido, y por eso es AQUÍ —no al pagar— donde
 * el inventario se descuenta (un pedido rechazado nunca movió stock) y donde se generan las comandas por área. Vive en el
 * kernel por lo mismo que {@see EcommerceOrderPaid}: lo escuchan varios módulos y ninguno debe conocer a `Ecommerce` —
 * `Inventory` descuenta, `Printing` imprime la comanda y `Floor` la manda a la pantalla de cocina (parte 2)—.
 *
 * Las líneas ya traen su **área de preparación** congelada (resuelta al hacer el pedido vía la sonda `AreaRouter`), así que
 * los oyentes reparten por área sin volver a resolver el ruteo. Un área `null` es legítima: ese item no se comanda.
 */
final readonly class EcommerceOrderAccepted implements CrossModuleEvent
{
    use Dispatchable;

    /**
     * @param  list<array{article_id: int, name: string, quantity: int, preparation_area_id: int|null}>  $items
     */
    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $orderUlid,
        public string $orderFolio,
        public int $customerId,
        public array $items,
        public string $acceptedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}
