<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * «¿A qué área de preparación le toca este artículo en esta sucursal?»
 *
 * El ruteo por área vive en `Pos` (la tabla `pos_area_routes` y su algoritmo de precedencia artículo → categoría → padre,
 * D240). La tienda en línea necesita el mismo ruteo para partir un pedido aceptado en comandas por área, pero `Ecommerce`
 * no puede depender de `Pos` (frontera de módulo). Lo pregunta por esta sonda del kernel: `Pos` la implementa envolviendo
 * su resolutor; `Ecommerce` la consume sin nombrar a `Pos`. Es la misma inversión que {@see StockAvailabilityProbe} y
 * {@see \App\Modules\Shared\Domain\Contracts\PromotionResolver}.
 *
 * El null-object devuelve `null` (ninguna área): sin `Pos` resolviendo el ruteo, un item no se comanda —igual que un item
 * sin regla de ruteo—, lo que nunca es peor que mandar una comanda a la impresora equivocada.
 */
interface AreaRouter
{
    /**
     * El id del área de preparación que le toca al artículo en la sucursal, o `null` si ninguna. Recibe y devuelve
     * primitivos: `Ecommerce` jamás toca un modelo de `Pos`.
     */
    public function routeForArticle(int $articleId, int $branchId): ?int;
}
