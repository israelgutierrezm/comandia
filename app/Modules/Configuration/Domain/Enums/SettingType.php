<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

use App\Modules\Configuration\Domain\Exceptions\InvalidSettingValueException;

/**
 * Tipo declarado de una llave de configuración (ARQUITECTURA_MAESTRA §5).
 *
 * El valor se guarda en una sola columna de texto (D79) y **este enum es la
 * autoridad sobre su tipo**: valida al escribir y convierte al leer. Sin él, un
 * `pos.blind_precount` guardado como `"false"` se leería como cadena no vacía y
 * por tanto como verdadero — el precorte ciego quedaría desactivado creyendo lo
 * contrario.
 */
enum SettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Decimal = 'decimal';
    case String = 'string';
    case Enum = 'enum';

    /**
     * Convierte el texto guardado al tipo declarado.
     */
    public function cast(string $raw): bool|int|string|float
    {
        return match ($this) {
            self::Bool => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            self::Int => (int) $raw,
            self::Decimal => (float) $raw,
            self::String, self::Enum => $raw,
        };
    }

    /**
     * Serializa un valor para guardarlo, validando que corresponda al tipo.
     *
     * @throws InvalidSettingValueException
     */
    public function serialize(string $key, mixed $value): string
    {
        return match ($this) {
            self::Bool => $this->serializeBool($key, $value),
            self::Int => $this->serializeInt($key, $value),
            self::Decimal => $this->serializeDecimal($key, $value),
            self::String, self::Enum => $this->serializeString($key, $value),
        };
    }

    private function serializeBool(string $key, mixed $value): string
    {
        if (! is_bool($value)) {
            throw InvalidSettingValueException::wrongType($key, 'booleano', $value);
        }

        // '1' y '0' y no 'true'/'false': es lo que `filter_var` interpreta sin
        // ambigüedad en ambos sentidos.
        return $value ? '1' : '0';
    }

    private function serializeInt(string $key, mixed $value): string
    {
        if (! is_int($value)) {
            throw InvalidSettingValueException::wrongType($key, 'entero', $value);
        }

        return (string) $value;
    }

    private function serializeDecimal(string $key, mixed $value): string
    {
        if (! is_int($value) && ! is_float($value)) {
            throw InvalidSettingValueException::wrongType($key, 'decimal', $value);
        }

        return (string) $value;
    }

    private function serializeString(string $key, mixed $value): string
    {
        if (! is_string($value)) {
            throw InvalidSettingValueException::wrongType($key, 'cadena', $value);
        }

        return $value;
    }
}
