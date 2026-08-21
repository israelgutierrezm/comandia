<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Zonas de un plano: terraza, salón, barra (§6.4, D34).
 *
 * ## Por qué una zona no es sólo una etiqueta
 *
 * Una mesa **pertenece** a una zona —`restaurant_tables.floor_zone_id` es NOT NULL—, así que la zona es la que ata la
 * mesa al plano. Eso tiene dos consecuencias que mandan sobre todo lo demás en este archivo:
 *
 * 1. **Un plano no puede quedarse sin zonas**, porque entonces no admitiría mesas. Por eso el alta del plano exige al
 *    menos una y por eso aquí no se puede borrar la última.
 * 2. **Borrar una zona con mesas borraría mesas**, y una mesa lleva cuentas colgando. La FK es `RESTRICT` y la base lo
 *    impediría de todos modos; el 409 existe para que el editor diga *qué* pasa en lugar de reventar con un error de
 *    integridad que no se puede leer.
 *
 * ## El orden se guarda, no se calcula
 *
 * `sort_order` va de diez en diez para que insertar entre dos no exija renumerar todas. Es la misma razón por la que
 * el alta del plano numera `(índice + 1) * 10`.
 */
final class FloorZoneController
{
    use AssertsBranchScope;

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, FloorPlan $floorPlan): JsonResponse
    {
        $this->assertBranchInScope((int) $floorPlan->branch_id);

        $validado = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        // Al final de la lista, que es donde se espera que aparezca lo recién creado. Reordenar es otro acto.
        $ultimo = (int) FloorZone::query()->where('floor_plan_id', $floorPlan->id)->max('sort_order');

        $zona = FloorZone::create([
            'floor_plan_id' => $floorPlan->id,
            'name' => $validado['name'],
            'sort_order' => $ultimo + 10,
        ]);

        $this->audit->log(
            action: AuditAction::FLOOR_ZONE_CREATED,
            auditable: $zona,
            after: ['plan' => $floorPlan->name, 'name' => $zona->name],
        );

        return new JsonResponse(['data' => $this->zona($zona->refresh())], 201);
    }

    public function update(Request $request, FloorZone $floorZone): JsonResponse
    {
        $this->assertBranchInScope((int) $floorZone->plan->branch_id);

        $validado = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $antes = $floorZone->only(['name', 'sort_order']);

        $floorZone->update($validado);

        $this->audit->log(
            action: AuditAction::FLOOR_ZONE_UPDATED,
            auditable: $floorZone,
            before: $antes,
            after: $floorZone->only(['name', 'sort_order']),
        );

        return new JsonResponse(['data' => $this->zona($floorZone->refresh())]);
    }

    public function destroy(FloorZone $floorZone): JsonResponse
    {
        $this->assertBranchInScope((int) $floorZone->plan->branch_id);

        // Con mesas dentro no se borra, ni siquiera si están archivadas: una mesa archivada conserva las cuentas de
        // cuando estuvo en el piso, y llevárselas por delante borraría el historial de dónde se sentó la gente.
        if ($floorZone->tables()->exists()) {
            throw new ConflictHttpException(
                'Esta zona todavía tiene mesas. Muévelas a otra zona antes de eliminarla.'
            );
        }

        // La última no se borra: un plano sin zonas no admite mesas, así que quedaría inservible y habría que
        // adivinar cómo repararlo desde la interfaz.
        if (FloorZone::query()->where('floor_plan_id', $floorZone->floor_plan_id)->count() === 1) {
            throw new ConflictHttpException(
                'Un plano necesita al menos una zona. Crea otra antes de eliminar ésta.'
            );
        }

        $this->audit->log(
            action: AuditAction::FLOOR_ZONE_DELETED,
            auditable: $floorZone,
            before: ['name' => $floorZone->name, 'plan' => $floorZone->plan->name],
        );

        $floorZone->delete();

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function zona(FloorZone $zona): array
    {
        return [
            'ulid' => $zona->ulid,
            'name' => $zona->name,
            'sort_order' => $zona->sort_order,
        ];
    }
}
