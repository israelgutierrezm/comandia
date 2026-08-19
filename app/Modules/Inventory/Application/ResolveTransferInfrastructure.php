<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Warehouse;

/**
 * El almacén de tránsito y el motivo de merma que las transferencias necesitan, creados al primer uso.
 *
 * ## Por qué al primer uso y no al dar de alta el negocio
 *
 * Un oyente de `TenantProvisioned` los crearía para los negocios nuevos y dejaría fuera a los que ya existen, así
 * que haría falta además una migración de relleno — y una migración que siembra datos por negocio es exactamente
 * el tipo de cosa que falla a medias en producción y hay que arreglar a mano.
 *
 * Resolver al primer uso es idempotente por construcción y no tiene relleno que hacer. El costo es que la primera
 * transferencia de un negocio crea dos filas de infraestructura, y es aceptable: no son datos de dominio que el
 * negocio administre, son piezas que el sistema necesita para poder escribir lo que va a escribir.
 *
 * Y la unicidad no depende de esto: `warehouses` tiene un índice único que garantiza **un solo** almacén de
 * tránsito por negocio, así que dos peticiones simultáneas no pueden crear dos.
 */
final class ResolveTransferInfrastructure
{
    /** El código reservado del almacén de tránsito. `ascii_bin`, así que las mayúsculas importan. */
    public const TRANSIT_CODE = 'TRANSITO';

    /** El motivo de la merma automática al recibir con diferencias. */
    public const TRANSIT_DIFFERENCE_REASON = 'Diferencia en tránsito';

    /**
     * El almacén donde vive la mercancía que va en camino.
     *
     * Sin sucursal, como el central y por una razón propia: la mercancía en viaje ya salió de una sucursal y
     * todavía no llegó a la otra, así que atribuirla a cualquiera de las dos sería falso.
     */
    public function transitWarehouse(): Warehouse
    {
        $existing = Warehouse::query()->where('kind', WarehouseKind::Transit->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        $transit = Warehouse::create([
            'branch_id' => null,
            'kind' => WarehouseKind::Transit,
            'code' => self::TRANSIT_CODE,
            'name' => 'Mercancía en tránsito',
            'status' => 'active',
        ]);

        return $transit->refresh();
    }

    /**
     * El motivo con el que se merma lo que salió y no llegó.
     *
     * Marcado como del sistema: si el negocio pudiera renombrarlo, las pérdidas del camión acabarían agrupadas bajo
     * un motivo que significa otra cosa y el reporte de D27 quedaría mintiendo.
     *
     * Nace con `requires_evidence` en falso porque la evidencia de una diferencia en tránsito es la propia
     * transferencia —dice quién envió, quién recibió y cuánto de cada cosa— y exigir una foto de mercancía que no
     * llegó no tiene sentido. El negocio puede activarlo si quiere pedir la carta del transportista.
     */
    public function transitDifferenceReason(): WasteReason
    {
        $existing = WasteReason::query()
            ->where('name', self::TRANSIT_DIFFERENCE_REASON)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $reason = WasteReason::create([
            'name' => self::TRANSIT_DIFFERENCE_REASON,
            'requires_evidence' => false,
            'status' => CatalogStatus::Active,
        ]);

        // `is_system` se pone DESPUÉS y por el query builder: el invariante del modelo bloquea cambiarlo, y ponerlo
        // en el `create` obligaría a meterlo en `$fillable` — que es justo lo que abriría la puerta a que un Form
        // Request lo aceptara del cliente.
        WasteReason::query()->whereKey($reason->id)->toBase()->update(['is_system' => true]);

        return $reason->refresh();
    }
}
