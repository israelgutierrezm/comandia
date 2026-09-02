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
     * @param  array<string, string>  $allowedLabels  etiqueta en español de cada valor permitido
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public bool|int|string|float $default,
        public SettingScope $maxScope,
        public string $module,
        public ?array $allowed = null,
        public string $description = '',
        public array $allowedLabels = [],
    ) {}

    /**
     * Los valores permitidos con su etiqueta para mostrar.
     *
     * El valor es un identificador —código, inglés— y la etiqueta es lo que lee la persona. La
     * pantalla de configuración pintaba los valores en crudo: `multiple_5`, `on_pickup`,
     * `branch_default`. Las etiquetas viven en el catálogo y no en el frontend porque la pantalla se
     * autoconfigura desde la API: una tabla de traducciones en Vue obligaría a tocar el frontend
     * cada vez que se agrega una llave, que es justo lo que ese diseño evita.
     *
     * Cae al valor cuando falta etiqueta, y hay un test que exige etiqueta a todo enumerado con más
     * de una opción — el caso en que la persona de verdad elige.
     *
     * @return list<array{value: string, label: string}>|null
     */
    public function allowedWithLabels(): ?array
    {
        if ($this->allowed === null) {
            return null;
        }

        return array_map(fn (string $value): array => [
            'value' => $value,
            'label' => $this->allowedLabels[$value] ?? $value,
        ], $this->allowed);
    }

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

    /**
     * ¿Se le ofrece esta llave al usuario en el panel de configuración?
     *
     * Un enumerado de una sola opción —`locale`, `currency` en v1 (México, MXN — D52)— no da elección: pintarlo sería
     * un control que no puede cambiar nada. La llave se queda en el catálogo (default y lecturas internas siguen), pero
     * no se ofrece. Regla auto-mantenible: cualquier enumerado futuro con una sola opción se oculta solo, y en cuanto
     * gana una segunda opción vuelve a ofrecerse sin tocar nada más.
     */
    public function isOfferedToUser(): bool
    {
        return ! ($this->type === SettingType::Enum && count($this->allowed ?? []) < 2);
    }

    public function allowsScope(SettingScope $scope): bool
    {
        return $scope->isAllowedBy($this->maxScope);
    }
}
