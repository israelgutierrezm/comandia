<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Exceptions;

use DomainException;

/**
 * Se intentó algo que una cuenta no admite (§6.3).
 */
final class PosAccountException extends DomainException
{
    public static function accountDoesNotAcceptItems(string $account, string $estado): self
    {
        return new self(sprintf(
            'La cuenta %s está %s y no admite más items. Una cuenta cobrada o cancelada no se puede seguir llenando: su '
            .'total ya se fijó.',
            $account,
            mb_strtolower($estado),
        ));
    }

    public static function itemsLockedAfterBillRequest(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s ya pidió la cuenta y este negocio bloquea la captura a partir de ahí. Si hace falta agregar '
            .'algo, vuelve a abrirla.',
            $account,
        ));
    }

    public static function articleNotSellable(string $article): self
    {
        return new self(sprintf(
            '«%s» no es un artículo vendible: no tiene precio de venta y no se puede cobrar.',
            $article,
        ));
    }

    public static function articleWithoutPrice(string $article): self
    {
        return new self(sprintf(
            '«%s» no tiene precio en esta sucursal. Captúralo antes de venderlo — cobrarlo en cero haría que el cliente '
            .'pagara de menos y nadie se enterara hasta el corte.',
            $article,
        ));
    }

    public static function membershipRequired(): self
    {
        return new self('No hay una persona en contexto a la que atribuir esta captura.');
    }

    public static function tableNotAvailable(string $table): self
    {
        return new self(sprintf(
            'La mesa %s no está disponible: tiene servicio en curso o está unida a otra.',
            $table,
        ));
    }

    public static function versionMismatch(string $account): self
    {
        return new self(sprintf(
            'La cuenta %s cambió mientras la tenías en pantalla. Vuelve a cargarla antes de continuar: alguien más pudo '
            .'agregar items o cobrarla.',
            $account,
        ));
    }
}
