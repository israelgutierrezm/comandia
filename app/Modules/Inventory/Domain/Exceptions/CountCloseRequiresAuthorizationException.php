<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

/**
 * El cierre del conteo reescribiría más inventario del que el negocio deja pasar sin firma.
 *
 * ## Por qué el conteo también tiene umbral, si ya exige un permiso aparte
 *
 * Porque los dos controles miden cosas distintas. `inventory.counts.close` dice **quién puede** cerrar; el umbral
 * dice **cuánto** puede absorber sin que nadie más firme. Sin él, cerrar un conteo podía castigar cincuenta mil
 * pesos de inventario con menos control que una merma de seiscientos — una incoherencia que el diseño no vio.
 *
 * ## Y por qué el umbral se mide en valor ABSOLUTO
 *
 * Un conteo con veinte mil de sobrante y veinte mil de faltante suma cero neto y aun así reescribe cuarenta mil
 * pesos de inventario. El neto dejaría pasar exactamente el caso que más urge revisar: el descuadre grande que se
 * compensa a sí mismo, que casi nunca es azar.
 */
final class CountCloseRequiresAuthorizationException extends RequiresAuthorizationException
{
    /**
     * @param  numeric-string  $value
     * @param  numeric-string  $threshold
     */
    public static function forValue(string $value, string $threshold): self
    {
        return new self(sprintf(
            'Este conteo reescribe $%s de inventario y el negocio exige autorización a partir de $%s. El conteo '
            .'sigue abierto y lo capturado no se pierde: pide al propietario que autorice con su PIN y vuelve a '
            .'cerrarlo con la autorización.',
            $value,
            $threshold,
        ));
    }

    public function requiredPermission(): string
    {
        return 'inventory.counts.authorize_above_threshold';
    }
}
