<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Domain\Enums\SettingScope;
use App\Modules\Configuration\Domain\Enums\SettingType;
use App\Modules\Configuration\Domain\Exceptions\InvalidSettingValueException;

/**
 * Definición de una llave de configuración: su contrato completo
 * (ARQUITECTURA_MAESTRA §5).
 *
 * Esto es lo que hace que el valor pueda guardarse en una sola columna de texto
 * (D79) sin perder tipado: la definición sabe el tipo, el default, hasta dónde se
 * puede sobrescribir, a qué módulo pertenece —para no mostrar llaves de módulos
 * inactivos— y, si es un enumerado, qué valores acepta.
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string>|null  $allowed  valores válidos si el tipo es `Enum`
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public bool|int|string|float $default,
        public SettingScope $maxScope,
        public string $module,
        public ?array $allowed = null,
        public string $description = '',
    ) {}

    /**
     * Valida y serializa un valor para guardarlo.
     *
     * @throws InvalidSettingValueException
     */
    public function serialize(mixed $value): string
    {
        $serialized = $this->type->serialize($this->key, $value);

        if ($this->type === SettingType::Enum && $this->allowed !== null
            && ! in_array($serialized, $this->allowed, strict: true)) {
            throw InvalidSettingValueException::notAllowed($this->key, $serialized, $this->allowed);
        }

        return $serialized;
    }

    public function cast(string $raw): bool|int|string|float
    {
        return $this->type->cast($raw);
    }

    public function allowsScope(SettingScope $scope): bool
    {
        return $scope->isAllowedBy($this->maxScope);
    }
}
