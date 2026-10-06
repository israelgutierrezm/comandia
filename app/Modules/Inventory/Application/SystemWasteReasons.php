<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use Illuminate\Database\QueryException;

/**
 * Los motivos de merma que pone el SISTEMA, no el negocio (D375).
 *
 * Marcados `is_system`: si el negocio pudiera renombrarlos, las mermas de un origen acabarían agrupadas bajo un motivo
 * que significa otra cosa y el reporte de mermas (D27) quedaría mintiendo. Es el mismo trato que «Diferencia en
 * tránsito» de las transferencias.
 *
 * Se crean la primera vez que hacen falta, por negocio: sembrarlos en todos los negocios de antemano sería un motivo
 * más en el catálogo de quien nunca lo usará.
 */
final readonly class SystemWasteReasons
{
    public const POS_CANCELLATION = 'Cancelación en POS';

    /**
     * El motivo de lo que la cocina ya había preparado y se canceló con destino «merma».
     */
    public function posCancellation(): WasteReason
    {
        return $this->named(self::POS_CANCELLATION);
    }

    private function named(string $nombre): WasteReason
    {
        $existente = WasteReason::query()->where('name', $nombre)->first();

        if ($existente !== null) {
            return $existente;
        }

        try {
            $motivo = WasteReason::create([
                'name' => $nombre,
                // La evidencia de esta merma es la cancelación misma: motivo, PIN de quien la autorizó y la comanda de
                // cancelación. Pedir una foto aquí no tendría sentido.
                'requires_evidence' => false,
                'status' => CatalogStatus::Active,
            ]);
        } catch (QueryException $e) {
            // Dos trabajos lo crearon a la vez: el índice único (negocio, nombre) dejó pasar a uno. Se usa ése.
            $otro = WasteReason::query()->where('name', $nombre)->first();

            if ($otro === null) {
                throw $e;
            }

            return $otro;
        }

        // `is_system` va DESPUÉS y por el query builder: el modelo no deja cambiarlo, y meterlo en `$fillable` abriría la
        // puerta a que un Form Request lo aceptara del cliente (igual que en `ResolveTransferInfrastructure`).
        WasteReason::query()->whereKey($motivo->id)->toBase()->update(['is_system' => true]);

        return $motivo->refresh();
    }
}
