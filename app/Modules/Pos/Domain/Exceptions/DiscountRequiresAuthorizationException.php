<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Exceptions;

use App\Modules\Pos\Domain\Enums\PosDiscountKind;
use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;

/**
 * Se intentó descontar sin autorización (§6.3, ADR-008).
 *
 * ## Sin umbral, y el PIN se pide SIEMPRE
 *
 * Incluso a quien tiene el permiso. No es desconfianza hacia el gerente: es que el permiso lo tiene la **sesión** —una
 * terminal abierta que cualquiera puede tocar mientras su dueño atiende una mesa— y el PIN lo tiene la **persona**. Lo
 * que se registra es quién estaba delante en ese momento, que es la única pregunta que un reporte de robo hormiga puede
 * contestar.
 *
 * Un umbral de monto sería tentador —«los descuentos chicos no molestan a nadie»— y abriría exactamente la puerta que
 * esto cierra: veinte descuentos pequeños en un turno.
 */
final class DiscountRequiresAuthorizationException extends RequiresAuthorizationException
{
    private function __construct(string $mensaje, private readonly string $permiso)
    {
        parent::__construct($mensaje);
    }

    public static function forKind(PosDiscountKind $kind, bool $deCuenta): self
    {
        if ($kind === PosDiscountKind::Courtesy) {
            return new self(
                'Registrar una cortesía necesita la autorización de un superior con su PIN: es comida que se preparó y '
                .'no se cobró.',
                'pos.discounts.courtesy',
            );
        }

        return new self(
            $deCuenta
                ? 'Descontar de la cuenta necesita la autorización de un superior con su PIN.'
                : 'Descontar de un item necesita la autorización de un superior con su PIN.',
            $deCuenta ? 'pos.discounts.apply_account' : 'pos.discounts.apply_item',
        );
    }

    public function requiredPermission(): string
    {
        return $this->permiso;
    }
}
