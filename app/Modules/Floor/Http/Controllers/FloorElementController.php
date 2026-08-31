<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Controllers;

use App\Modules\Floor\Http\Requests\SaveFloorElementRequest;
use App\Modules\Floor\Http\Resources\FloorElementResource;
use App\Modules\Floor\Infrastructure\Models\FloorElement;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Elementos decorativos del salón: muros, puertas y rótulos (ADR-011).
 *
 * No se auditan uno a uno como las mesas: un muro no tiene peso operativo ni financiero, y el guardado del layout —que sí
 * se audita— ya registra el reacomodo. Se BORRAN de verdad: nada histórico apunta a un muro.
 */
final class FloorElementController
{
    use AssertsBranchScope;

    /** Tamaño (cm) por omisión de cada tipo; el elemento nace centrado en el lienzo. */
    private const DEFAULTS = [
        'wall' => ['width' => 200, 'height' => 15],
        'door' => ['width' => 90, 'height' => 15],
        'label' => ['width' => 180, 'height' => 40],
    ];

    public function store(SaveFloorElementRequest $request, FloorPlan $floorPlan): JsonResponse
    {
        $this->assertBranchInScope((int) $floorPlan->branch_id);

        $kind = $request->string('kind')->toString();
        $medidas = self::DEFAULTS[$kind];

        $width = (float) $request->input('width', $medidas['width']);
        $height = (float) $request->input('height', $medidas['height']);

        // Nace centrado en el lienzo, como una mesa nueva, para que aparezca a la vista listo para arrastrar.
        $x = max(0.0, ((float) $floorPlan->canvas_width - $width) / 2);
        $y = max(0.0, ((float) $floorPlan->canvas_height - $height) / 2);

        $element = FloorElement::create([
            'floor_plan_id' => $floorPlan->id,
            'kind' => $kind,
            'text' => $request->filled('text') ? $request->string('text')->toString() : null,
            'x' => $request->input('x', number_format($x, 2, '.', '')),
            'y' => $request->input('y', number_format($y, 2, '.', '')),
            'width' => number_format($width, 2, '.', ''),
            'height' => number_format($height, 2, '.', ''),
            'rotation' => $request->input('rotation', '0.00'),
        ]);

        return (new FloorElementResource($element->refresh()))->response()->setStatusCode(201);
    }

    public function update(SaveFloorElementRequest $request, FloorElement $floorElement): FloorElementResource
    {
        $this->assertBranchInScope((int) $floorElement->plan->branch_id);

        $floorElement->update($request->safe()->except('kind'));

        return new FloorElementResource($floorElement->refresh());
    }

    public function destroy(FloorElement $floorElement): Response
    {
        $this->assertBranchInScope((int) $floorElement->plan->branch_id);

        $floorElement->delete();

        return response()->noContent();
    }
}
