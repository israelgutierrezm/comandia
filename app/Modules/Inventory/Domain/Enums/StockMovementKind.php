<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Tipos de movimiento de inventario (§6.2).
 *
 * Cada tipo dice **por qué** se movió la existencia, y eso es lo que hace legible un kardex: un reporte de
 * mermas del mes es un filtro por tipo, no una tabla aparte.
 *
 * ## La dirección es del TIPO, no del llamador
 *
 * Una recepción de compra entra y una merma sale; eso no lo decide quien registra el movimiento. Sólo los
 * ajustes admiten las dos direcciones, porque un ajuste puede sumar o restar por naturaleza.
 *
 * Dejar que el llamador eligiera la dirección de cualquier tipo permitiría registrar una merma que **suma**
 * existencia, y ese movimiento no fallaría en ningún sitio: se acumularía en el saldo y en el reporte de
 * mermas del mes con signo contrario.
 */
enum StockMovementKind: string
{
    /** Recepción de compra confirmada. */
    case PurchaseReceipt = 'purchase_receipt';

    /** Salida por transferencia hacia otro almacén. */
    case TransferOut = 'transfer_out';

    /** Entrada por transferencia desde otro almacén. */
    case TransferIn = 'transfer_in';

    /** Entrada del artículo producible al completarse una producción. */
    case ProductionIn = 'production_in';

    /** Consumo de un insumo por una producción. */
    case ProductionOut = 'production_out';

    /** Descuento por venta. Asíncrono y nunca bloqueante (§6.2). */
    case SaleConsumption = 'sale_consumption';

    /** Reverso de un descuento por venta: cancelación de un item ya comandado. */
    case SaleReturn = 'sale_return';

    /** Merma tipificada, con motivo del catálogo del tenant (D27). */
    case Waste = 'waste';

    /** Ajuste por diferencia de conteo físico (D24). Suma o resta. */
    case CountAdjustment = 'count_adjustment';

    /** Ajuste manual con motivo escrito. Suma o resta. */
    case ManualAdjustment = 'manual_adjustment';

    /** Carga inicial de existencias al empezar a usar el sistema. */
    case InitialLoad = 'initial_load';

    /**
     * La dirección fija de este tipo, o `null` si admite las dos.
     *
     * `null` NO significa «da igual»: significa que el llamador tiene que decidirla, y los dos tipos que
     * lo permiten son los ajustes, donde el signo es la información.
     */
    public function fixedDirection(): ?StockMovementDirection
    {
        return match ($this) {
            self::PurchaseReceipt,
            self::TransferIn,
            self::ProductionIn,
            self::SaleReturn,
            self::InitialLoad => StockMovementDirection::In,

            self::TransferOut,
            self::ProductionOut,
            self::SaleConsumption,
            self::Waste => StockMovementDirection::Out,

            self::CountAdjustment,
            self::ManualAdjustment => null,
        };
    }

    /**
     * ¿Este movimiento es una ADQUISICIÓN con costo propio?
     *
     * Las entradas por compra y la carga inicial traen su costo del documento; las demás lo heredan del
     * costo vigente del artículo. Es la misma distinción que `CostOrigin::isAcquisition()` en el costeo, y
     * por la misma razón: promediar costos heredados con costos pagados da un número sin significado.
     */
    public function carriesOwnCost(): bool
    {
        return in_array($this, [self::PurchaseReceipt, self::InitialLoad], strict: true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Recepción de compra',
            self::TransferOut => 'Salida por transferencia',
            self::TransferIn => 'Entrada por transferencia',
            self::ProductionIn => 'Producción',
            self::ProductionOut => 'Consumo por producción',
            self::SaleConsumption => 'Consumo por venta',
            self::SaleReturn => 'Reverso de venta',
            self::Waste => 'Merma',
            self::CountAdjustment => 'Ajuste por conteo',
            self::ManualAdjustment => 'Ajuste manual',
            self::InitialLoad => 'Carga inicial',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
