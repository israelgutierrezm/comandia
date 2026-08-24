<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Routing;

use App\Modules\Shared\Domain\Contracts\AreaRouter;

/**
 * Null-object de {@see AreaRouter}: sin `Pos` resolviendo el ruteo, ningún artículo tiene área y por tanto no se comanda.
 * Preferible a adivinar un área: una comanda en la impresora equivocada es peor que ninguna.
 */
final class NullAreaRouter implements AreaRouter
{
    public function routeForArticle(int $articleId, int $branchId): ?int
    {
        return null;
    }
}
