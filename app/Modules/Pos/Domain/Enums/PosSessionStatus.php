<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Enums;

/**
 * Estado de una sesión de caja (§6.3).
 *
 * `open → precounted → closed`, con el precorte opcional según la configuración del negocio.
 */
enum PosSessionStatus: string
{
    case Open = 'open';

    /**
     * El precorte ya ocurrió y **la caja sigue operando**.
     *
     * No es un estado terminal: es un sello. El precorte ciego (§6.3) existe para que el cajero declare lo que hay sin
     * ver lo esperado, y su valor está en que se hace **antes** de cerrar — cuando todavía puede aparecer un pago más y
     * la declaración ya está firmada.
     */
    case Precounted = 'precounted';

    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Precounted => 'Precorte hecho',
            self::Closed => 'Cerrada',
        };
    }

    /** ¿Se puede cobrar en esta sesión? */
    public function acceptsOperations(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * Los estados a los que se puede pasar desde éste.
     *
     * Se expone al cliente en el recurso, como en las transferencias: si la interfaz deduce las transiciones, acaba con
     * su propia copia de la máquina de estados y se desincroniza en la primera iteración que añada un paso (D139).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            // Se puede cerrar sin pasar por el precorte: un negocio que no precuenta no debe tener que atravesar un
            // paso vacío. Quién ve el esperado del corte es otra cosa y lo deciden los permisos, no un ajuste (D289).
            self::Open => [self::Precounted, self::Closed],
            self::Precounted => [self::Closed],
            self::Closed => [],
        };
    }
}
