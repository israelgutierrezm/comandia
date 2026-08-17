<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Resources;

use App\Modules\Configuration\Domain\SettingDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una llave de configuración con todo lo que el frontend necesita para pintar su control.
 *
 * El cliente no lleva una tabla de tipos ni de valores válidos: los recibe. Es la misma idea que
 * el motor de reportes de ADR-006 —el frontend se autoconfigura desde la definición— aplicada a
 * la configuración, y evita que agregar una llave exija tocar el frontend.
 *
 * `value` es el valor EFECTIVO tras la cascada; `is_overridden` dice si en este nivel hay un
 * override explícito. Los dos hacen falta: sin el segundo, la UI no puede distinguir "hereda 16%"
 * de "está configurado en 16%", y esa diferencia importa el día que cambie el default.
 */
final class SettingResource extends JsonResource
{
    public function __construct(
        private readonly SettingDefinition $definition,
        private readonly bool|int|string|float $value,
        private readonly bool $isOverridden,
        private readonly bool|int|string|float $inheritedValue,
    ) {
        parent::__construct($definition);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->definition->key,
            'module' => $this->definition->module,
            'description' => $this->definition->description,

            'type' => $this->definition->type->value,
            'allowed_values' => $this->definition->allowed,

            // Hasta qué nivel se puede sobrescribir: la UI deshabilita el control en la pantalla
            // de sucursal para las llaves que sólo llegan a tenant, en lugar de dejar que el
            // usuario descubra el 422 al guardar.
            'max_scope' => $this->definition->maxScope->value,

            'value' => $this->value,
            'is_overridden' => $this->isOverridden,

            // El valor que quedaría si se quitara el override. Permite a la UI ofrecer
            // "restaurar" mostrando a qué valor se va a restaurar.
            'inherited_value' => $this->inheritedValue,

            'default_value' => $this->definition->default,
        ];
    }
}
