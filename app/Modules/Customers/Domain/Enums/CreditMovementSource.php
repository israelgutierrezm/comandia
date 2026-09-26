<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Enums;

/**
 * De qué documento salió un movimiento de crédito, tal como lo publica la API.
 *
 * ## Por qué no se publica lo que guarda la columna
 *
 * `source_type` guarda el nombre de CLASE del documento: es parte de la llave de idempotencia (documento, tipo) y así
 * lo escribe el cobro a crédito. Es un dato interno —cambia con un refactor y obliga a quien lo lee a conocer la
 * estructura del código—, así que la API publica esta clave estable y su etiqueta en español.
 *
 * La traducción es una tabla explícita y no `class_basename()`: renombrar una clase no debe cambiar un contrato de la API
 * sin que nadie lo decida. Un tipo guardado que la tabla no conozca se publica como `other`, nunca por su nombre.
 */
enum CreditMovementSource: string
{
    /** Una cuenta del punto de venta cobrada a crédito: el cargo del fiado. */
    case PosAccount = 'pos_account';

    /** Un documento que esta tabla todavía no conoce. */
    case Other = 'other';

    /**
     * Traduce lo guardado en `customer_credit_movements.source_type`.
     *
     * Null cuando el movimiento no nace de otro documento: un abono se registra en caja y él mismo es el documento
     * (el diario lo cita por su propio ULID).
     */
    public static function fromStored(?string $storedType): ?self
    {
        return match ($storedType) {
            null, '' => null,

            // Una CADENA y no una referencia a la clase: este módulo no conoce al punto de venta —la dependencia va al
            // revés— y lo que se compara es el dato que el cobro escribió, igual que los `sourceType` del diario.
            'App\Modules\Pos\Infrastructure\Models\PosAccount' => self::PosAccount,

            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PosAccount => 'Cuenta del punto de venta',
            self::Other => 'Otro documento',
        };
    }
}
