<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Publishing;

use App\Modules\Shared\Domain\Contracts\ArticleCoverProbe;

/**
 * Null-object de {@see ArticleCoverProbe}: sin `Publishing` resolviendo la sonda, el POS no tiene portadas y pinta cuadros
 * sin foto. Preferible a fallar por un módulo que no respondió.
 */
final class NullArticleCoverProbe implements ArticleCoverProbe
{
    public function coversFor(array $articleIds): array
    {
        return [];
    }
}
