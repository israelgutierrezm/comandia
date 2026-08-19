<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Estado de un conteo físico (D24, §6.2).
 *
 * ## Tres estados, no los cuatro del diseño
 *
 * El §2.6 proponía `draft → counting → closed | cancelled`. `draft` se quitó: su único contenido sería «existe
 * el conteo pero todavía no se congeló lo esperado», y no hay ningún momento del trabajo real que corresponda a
 * eso. Congelar es lo primero que pasa —es el equivalente a imprimir la hoja de conteo— y separarlo del alta
 * abriría una ventana en la que la hoja impresa y lo congelado pueden diferir, que es precisamente el problema
 * que congelar existe para evitar.
 *
 * El alcance del conteo (todo el almacén, o sólo las carnes) se elige **al crearlo**, en la misma petición.
 *
 * ## `cancelled` hace falta más de lo que parecía
 *
 * Sólo puede haber un conteo abierto por almacén, y es un índice único de verdad. Sin cancelación, un conteo
 * empezado por error dejaría ese almacén sin poder volver a contarse nunca. Cancelar **descarta** lo capturado:
 * no aplica ninguna diferencia.
 */
enum StockCountStatus: string
{
    /** Congelado y en captura. El almacén sigue operando con normalidad: un conteo no bloquea nada. */
    case Counting = 'counting';

    /** Las diferencias ya se aplicaron al kardex. Inmutable: corregir un conteo mal hecho es hacer otro. */
    case Closed = 'closed';

    /** Se descartó sin aplicar nada. */
    case Cancelled = 'cancelled';

    /** ¿Admite captura y cierre? */
    public function isOpen(): bool
    {
        return $this === self::Counting;
    }

    public function label(): string
    {
        return match ($this) {
            self::Counting => 'En captura',
            self::Closed => 'Cerrado',
            self::Cancelled => 'Cancelado',
        };
    }
}
