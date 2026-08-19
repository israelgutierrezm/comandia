<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Estados de una transferencia entre almacenes (D25, §6.2).
 *
 * ```
 * requested → authorized → preparing → shipped → received
 *                                             ↘ received_with_differences
 *        ↘ cancelled (desde requested o authorized)
 * ```
 *
 * ## Las transiciones se declaran, no se deducen
 *
 * `allowedNext()` es la lista completa y es el único sitio donde vive. Un `if` por endpoint funcionaría igual hasta
 * el día que alguien añada un estado y se olvide de uno de los cinco — y el fallo sería silencioso: una
 * transferencia que retrocede de `shipped` a `preparing` desharía movimientos de kardex ya escritos, que es
 * imposible porque el kardex es inmutable.
 *
 * ## Dos pasos son omitibles, y su omisión no borra el hecho
 *
 * `authorized` y `preparing` se activan por configuración. Cuando están omitidos, el flujo por omisión es
 * **solicitar → enviar → recibir**: tres pasos, cada uno con un hecho físico detrás. Los cinco tienen sentido en
 * una cadena con bodega central y encargado; en una fonda de dos sucursales que se presta un costal de arroz, cinco
 * peticiones y dos personas garantizan que la gente deje de usar transferencias y registre entradas y salidas
 * manuales — perdiendo justamente el documento que las relaciona.
 *
 * Activar los pasos después **no invalida las transferencias viejas**: sus sellos de autorización y preparación
 * quedan nulos, y un sello nulo dice «este paso no se pedía entonces», que es la verdad.
 *
 * ## `received_with_differences` es un estado y no una bandera
 *
 * Porque cambia lo que ya ocurrió y no sólo cómo se muestra: recibir con diferencias deja mercancía sin llegar y
 * genera la merma en tránsito. Una bandera sobre `received` invitaría a listar «las recibidas» y encontrarse las
 * dos mezcladas, que es el reporte que nadie quiere.
 */
enum TransferStatus: string
{
    case Requested = 'requested';
    case Authorized = 'authorized';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case Received = 'received';
    case ReceivedWithDifferences = 'received_with_differences';
    case Cancelled = 'cancelled';

    /**
     * Los estados a los que se puede pasar desde éste, con TODOS los pasos activos.
     *
     * La omisión de pasos se resuelve en el servicio y no aquí: este enum describe la máquina completa, y un enum
     * que dependiera de la configuración del negocio dejaría de ser una declaración para volverse una regla con
     * estado.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Authorized, self::Preparing, self::Shipped, self::Cancelled],
            self::Authorized => [self::Preparing, self::Shipped, self::Cancelled],
            self::Preparing => [self::Shipped],
            self::Shipped => [self::Received, self::ReceivedWithDifferences],

            // Terminales. Una transferencia recibida no se corrige: se hace otra en sentido contrario, por lo
            // mismo que un conteo cerrado (D175) — sus movimientos ya están en el kardex, que es inmutable.
            self::Received, self::ReceivedWithDifferences, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), strict: true);
    }

    /** ¿Sigue viva? */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Received, self::ReceivedWithDifferences, self::Cancelled], strict: true);
    }

    /**
     * ¿La mercancía ya salió del origen?
     *
     * Es la frontera de la cancelación: antes de enviar no hay nada escrito en el kardex y cancelar es gratis;
     * después, la mercancía está en tránsito y el único cierre posible es recibirla.
     */
    public function hasShipped(): bool
    {
        return in_array(
            $this,
            [self::Shipped, self::Received, self::ReceivedWithDifferences],
            strict: true,
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitada',
            self::Authorized => 'Autorizada',
            self::Preparing => 'En preparación',
            self::Shipped => 'Enviada',
            self::Received => 'Recibida',
            self::ReceivedWithDifferences => 'Recibida con diferencias',
            self::Cancelled => 'Cancelada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
