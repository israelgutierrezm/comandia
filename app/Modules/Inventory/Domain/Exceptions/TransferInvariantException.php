<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\TransferStatus;
use RuntimeException;

/**
 * La transferencia no admite la operación que se le pidió.
 *
 * Son invariantes del documento y de su máquina de estados, no de la captura: dependen del estado en el instante de
 * la escritura, y comprobarlas al validar dejaría una ventana entre la comprobación y el efecto. El servicio las
 * verifica con la fila bloqueada.
 */
final class TransferInvariantException extends RuntimeException
{
    public static function cannotTransition(TransferStatus $from, TransferStatus $to): self
    {
        return new self(sprintf(
            'Una transferencia %s no puede pasar a %s.',
            mb_strtolower($from->label()),
            mb_strtolower($to->label()),
        ));
    }

    public static function requiresAuthorizationFirst(): self
    {
        return new self(
            'Este negocio exige autorizar las transferencias antes de enviarlas. Autoriza esta primero.'
        );
    }

    public static function requiresPreparationFirst(): self
    {
        return new self(
            'Este negocio exige registrar la preparación antes de enviar. Marca esta como preparada primero.'
        );
    }

    public static function stepNotEnabled(string $step): self
    {
        return new self(sprintf(
            'Este negocio no usa el paso de %s en las transferencias. Actívalo en la configuración si lo necesitas.',
            $step,
        ));
    }

    public static function alreadyShipped(): self
    {
        return new self(
            'Esta transferencia ya salió del almacén de origen y no se puede cancelar: la mercancía está en '
            .'tránsito. Recíbela —con diferencias si hace falta— y haz otra en sentido contrario.'
        );
    }

    public static function nothingShipped(): self
    {
        return new self(
            'No se envió ninguna cantidad. Una transferencia sin mercancía no mueve nada y no debería existir: '
            .'cancélala en lugar de enviarla vacía.'
        );
    }

    public static function shippedMoreThanRequested(string $article): self
    {
        return new self(sprintf(
            'De «%s» se está enviando más de lo que se pidió. Para mandar más, pide más: la cantidad solicitada '
            .'es lo que hace posible saber después si se pidió poco o se surtió poco.',
            $article,
        ));
    }

    public static function receivedMoreThanShipped(string $article): self
    {
        return new self(sprintf(
            'De «%s» está llegando más de lo que salió. Si el destino tiene mercancía extra, regístrala como '
            .'entrada aparte: meterla aquí haría que el sistema inventara existencia que nunca salió de ningún lado.',
            $article,
        ));
    }

    public static function transitWarehouseIsNotOperable(): self
    {
        return new self(
            'El almacén de mercancía en tránsito lo escriben sólo las transferencias. No admite movimientos '
            .'manuales: lo que hay ahí es lo que va en camino, y editarlo a mano dejaría mercancía sin dueño.'
        );
    }

    public static function centralToCentralNeedsBranch(): self
    {
        return new self(
            'Una transferencia entre dos almacenes centrales no se puede foliar: el folio va por sucursal (§7) y '
            .'ningún central tiene una. Pasa la mercancía por un almacén de sucursal, o registra dos movimientos '
            .'manuales con su nota.'
        );
    }
}
