<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

/**
 * La merma pasa el umbral del negocio y no traía autorización (D27, §6.2).
 *
 * No es un error de captura: los datos son correctos y la operación es legítima. Lo que falta es que **otra
 * persona** ponga su PIN, así que el mensaje dice el monto, el umbral y qué hacer — sin eso, quien captura ve un
 * rechazo y no sabe si corregir la cantidad o buscar al gerente.
 */
final class WasteRequiresAuthorizationException extends RequiresAuthorizationException
{
    /**
     * @param  numeric-string  $value
     * @param  numeric-string  $threshold
     */
    public static function forValue(string $value, string $threshold): self
    {
        return new self(sprintf(
            'Esta merma vale $%s y el negocio exige autorización a partir de $%s. Pide a un superior que '
            .'autorice con su PIN y vuelve a enviarla con la autorización.',
            $value,
            $threshold,
        ));
    }

    public function requiredPermission(): string
    {
        return 'inventory.waste.authorize_above_threshold';
    }
}
