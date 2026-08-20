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

    public static function itemsNotInAccount(string $account): self
    {
        return new self(sprintf(
            'Alguno de los items que pediste cancelar no está en la cuenta %s. Vuelve a cargarla: pudo moverse a otra '
            .'cuenta o cancelarse ya.',
            $account,
        ));
    }

    public static function itemAlreadyCancelled(string $article): self
    {
        return new self(sprintf(
            '«%s» ya está cancelado. Cancelarlo otra vez emitiría una segunda comanda de cancelación al área, y la '
            .'cocina no sabría si son dos platos o el mismo dos veces.',
            $article,
        ));
    }

    /**
     * Cancelar algo ya comandado exige motivo.
     *
     * El mismo argumento que en las mermas (D27) y los retiros: sin motivo, una venta que desaparece es una venta que
     * nadie puede explicar. Y aquí importa más, porque hay comida hecha de por medio.
     */
    public static function cancellationReasonRequired(): self
    {
        return new self(
            'Cancelar un item ya comandado exige un motivo de al menos 3 caracteres: alguien preparó eso y el corte '
            .'tiene que poder explicar por qué no se cobró.',
        );
    }

    /**
     * Y exige decir qué se hizo con la comida.
     *
     * Podría inferirse del estado —«si está servido, es merma»— y sería adivinar: un plato marcado servido puede no
     * haberse tocado, y uno en «preparando» puede llevar media hora en la plancha. Quien está ahí lo sabe y el sistema
     * no. Del destino depende que el inventario registre una merma o devuelva el producto, así que adivinarlo movería
     * existencias a ciegas.
     */
    public static function cancellationDestinationRequired(): self
    {
        return new self(
            'Di qué se hizo con el producto: «waste» si ya estaba preparado y se tira, «restock» si no se tocó y vuelve '
            .'al inventario. De eso depende que se registre una merma o no.',
        );
    }

    public static function orderNotInAccount(string $account): self
    {
        return new self(sprintf(
            'Esa orden no pertenece a la cuenta %s.',
            $account,
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
