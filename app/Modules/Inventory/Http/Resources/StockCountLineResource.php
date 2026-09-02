<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Infrastructure\Models\StockCountLine;
use App\Modules\Shared\Application\Authorization\Authorize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockCountLine
 *
 * Un renglón de la hoja de conteo. **Aquí vive el conteo ciego.**
 *
 * ## Quien cuenta no ve lo esperado
 *
 * `expected_quantity`, `variance` y la valuación sólo viajan a quien tiene `inventory.counts.close`. Es el control
 * estándar de inventarios y la razón es sencilla: si el almacenista lee «esperado: 40», escribe 40 y no cuenta. El
 * conteo dejaría de ser evidencia de nada y se volvería una confirmación de lo que el sistema ya creía — que es
 * exactamente lo que §6.2 quiere reconciliar.
 *
 * **No es una regla nueva:** es el mismo control que §6.3 ya aplica al efectivo con el precorte ciego (D289), donde el
 * cajero declara su caja sin ver el monto esperado —y por el mismo camino: un permiso, no un ajuste. La misma lógica,
 * el mismo motivo.
 *
 * Y sale gratis del reparto de permisos que ya existía: el almacenista **cuenta** (`counts.create`) y no **cierra**
 * (`counts.close`), así que la frontera del control coincide con una frontera que ya estaba dibujada. No hizo falta
 * un ajuste nuevo — y un ajuste habría sido peor, porque un control que se puede apagar se apaga.
 *
 * Cuesta algo, y conviene decirlo: quien captura no puede detectar su propio dedazo comparando con lo esperado. Lo
 * detecta quien revisa antes de cerrar, que es quien debe detectarlo.
 */
final class StockCountLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $puedeVerDiferencias = app(Authorize::class)->allows('inventory.counts.close');

        return [
            'article' => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
                'base_unit_code' => $this->article->baseUnit?->code,
            ],

            'lot' => $this->lot === null ? null : [
                'ulid' => $this->lot->ulid,
                'code' => $this->lot->code,
                'expires_at' => $this->lot->expires_at?->toDateString(),
            ],

            // Lo que sí ve todo el mundo: lo que se capturó. `null` = no contado, y la UI tiene que distinguirlo
            // de un cero — son ajustes distintos al cerrar.
            'counted_quantity' => $this->counted_quantity,
            'was_counted' => $this->wasCounted(),

            // El bloque ciego.
            ...$puedeVerDiferencias ? [
                'expected_quantity' => $this->expected_quantity,
                'variance' => $this->variance,
                'unit_cost_at_count' => $this->unit_cost_at_count,
                'variance_value' => $this->varianceValue(),
            ] : [],

            // El ajuste que este renglón generó, si el conteo ya se cerró. Es el enlace conteo → kardex.
            'adjustment_movement_ulid' => $this->whenLoaded(
                'adjustmentMovement',
                fn () => $this->adjustmentMovement?->ulid,
            ),
        ];
    }
}
