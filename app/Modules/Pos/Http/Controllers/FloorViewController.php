<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Floor\Infrastructure\Models\FloorElement;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El piso de venta: el salón dibujado, con lo que está pasando encima (§6.4).
 *
 * ## Por qué vive en `Pos` y no en `Floor`
 *
 * Porque junta dos cosas de dos módulos: la geometría del salón, que es de `Floor`, y la cuenta que ocupa cada mesa,
 * que es de `Pos`. La dirección permitida es `Pos → Floor` (`Floor` no depende de nadie), así que el que junta tiene
 * que ser el POS. Servirlo desde `Floor` obligaría a `Floor` a conocer las cuentas y cerraría un ciclo en el punto más
 * caliente del sistema, que es justo lo que ADR-001 evita.
 *
 * El **editor** es la otra mitad y sí vive en `Floor`: dibujar el salón no necesita saber quién está sentado.
 *
 * ## Una sola petición
 *
 * Plano, zonas, mesas y cuentas. Con cuatro llamadas la pantalla pintaría el salón vacío y le iría cayendo el estado
 * encima, y en una pantalla que alguien mira de reojo mientras carga platos eso se lee como que el sistema perdió las
 * cuentas.
 *
 * Es además el endpoint del que tira el **respaldo de polling**: cuando el socket no está, esto es lo que se vuelve a
 * pedir cada diez segundos, así que tiene que bastarse solo.
 *
 * ## Lo que NO trae: dinero
 *
 * Ni totales ni lo que falta por cobrar. El permiso de esta pantalla es `floor.layouts.view`, que tiene todo el que
 * atiende, y el de ver importes es otro. Pintar «$450» sobre una mesa concedería por la vía de atrás un permiso que el
 * negocio quizá no dio — y el importe está a un clic, en la cuenta, donde sí se comprueba.
 *
 * Sí trae **cuántos artículos** lleva la mesa y desde cuándo está ocupada, que es lo que se mira desde lejos para
 * saber a quién hay que ir a atender.
 */
final class FloorViewController
{
    use AssertsBranchScope;

    public function __invoke(Request $request, Branch $branch): JsonResponse
    {
        $this->assertBranchInScope((int) $branch->id);

        $plan = $this->plan($request, $branch);

        // Las ARCHIVADAS quedan fuera, con una excepción que importa: una mesa retirada con cuenta abierta encima
        // tiene que seguir viéndose hasta que se cobre. Si desapareciera, la cuenta quedaría invisible en el piso y
        // sólo alcanzable por el listado — y nadie la buscaría ahí.
        $mesas = RestaurantTable::query()
            ->where('floor_zone_id', '!=', 0)
            ->whereIn('floor_zone_id', $plan->zones->pluck('id'))
            ->with(['zone', 'joinedTo', 'joinedTables'])
            ->orderBy('code')
            ->get();

        $cuentas = PosAccount::query()
            ->open()
            ->whereNotNull('table_id')
            ->whereIn('table_id', $mesas->pluck('id'))
            // `displayName()` lee `restaurantTable->code`, así que la mesa de la cuenta se precarga: sin esto, con lazy
            // loading prohibido (dev/pruebas), pintar el piso con una mesa ocupada reventaba en 500.
            ->with('restaurantTable')
            ->withCount([
                'items',
                // Los que faltan por comandar: capturados pero aún no mandados a preparar. Es el estado «pendiente por
                // comandar» del piso de cuentas —una mesa con comida sin echar a andar—, y NO es un importe: es un
                // conteo, del mismo tipo que `items_count`, así que no cruza la línea del permiso de dinero.
                'items as pending_to_command_count' => fn ($q) => $q->where('status', PosOrderItemStatus::Captured->value),
            ])
            ->get()
            ->keyBy('table_id');

        $mesas = $mesas->reject(
            fn (RestaurantTable $m): bool => $m->isArchived() && ! $cuentas->has($m->id),
        )->values();

        return new JsonResponse([
            'data' => [
                'branch' => ['ulid' => $branch->ulid, 'name' => $branch->name],

                'plan' => [
                    'ulid' => $plan->ulid,
                    'name' => $plan->name,
                    'canvas' => [
                        'width' => $plan->canvas_width,
                        'height' => $plan->canvas_height,
                        'unit' => 'cm',
                    ],
                    'zones' => $plan->zones->map(fn ($z): array => [
                        'ulid' => $z->ulid,
                        'name' => $z->name,
                        'sort_order' => $z->sort_order,
                    ])->all(),
                ],

                'tables' => $mesas->map(fn (RestaurantTable $m): array => $this->table($m, $cuentas->get($m->id)))->all(),

                // Los elementos decorativos (ADR-011) también se dibujan en el piso, para orientar: los muros y las
                // puertas ayudan a ubicar la mesa. No son interactivos aquí.
                'elements' => $plan->elements->map(fn (FloorElement $e): array => [
                    'ulid' => $e->ulid,
                    'kind' => $e->kind,
                    'text' => $e->text,
                    'geometry' => [
                        'x' => $e->x,
                        'y' => $e->y,
                        'width' => $e->width,
                        'height' => $e->height,
                        'rotation' => $e->rotation,
                    ],
                ])->all(),
            ],
        ]);
    }

    /**
     * El plano a dibujar: el pedido, o el de omisión de la sucursal.
     */
    private function plan(Request $request, Branch $branch): FloorPlan
    {
        $builder = FloorPlan::query()->where('branch_id', $branch->id)->with(['zones', 'elements']);

        $plan = $request->filled('plan')
            ? (clone $builder)->where('ulid', $request->string('plan')->toString())->first()
            : (clone $builder)->where('is_default', true)->first();

        // Sin plano por omisión se toma el primero antes de rendirse: una sucursal con planos pero ninguno marcado
        // dejaría la pantalla en 404 teniendo todo lo necesario para dibujarse.
        $plan ??= (clone $builder)->orderBy('name')->first();

        if ($plan === null) {
            throw new NotFoundHttpException('Esta sucursal todavía no tiene un plano de salón.');
        }

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function table(RestaurantTable $mesa, ?PosAccount $cuenta): array
    {
        return [
            'ulid' => $mesa->ulid,
            'code' => $mesa->code,
            'name' => $mesa->name,
            'seats' => $mesa->seats,
            'effective_seats' => $mesa->effectiveSeats(),
            'status' => $mesa->status->value,
            'status_label' => $mesa->status->label(),
            'is_available' => $mesa->isAvailable(),
            'is_archived' => $mesa->isArchived(),

            'geometry' => [
                'x' => $mesa->x,
                'y' => $mesa->y,
                'width' => $mesa->width,
                'height' => $mesa->height,
                'rotation' => $mesa->rotation,
                'shape' => $mesa->shape,
            ],

            'zone_ulid' => $mesa->zone?->ulid,

            // La mesa unida no se dibuja como una mesa suelta: forma parte de un conjunto que atiende una sola cuenta,
            // y la pantalla necesita saberlo para no ofrecer sentar gente en la mitad de una mesa de ocho.
            'joined_to' => $mesa->joinedTo?->ulid,

            'account' => $cuenta === null ? null : [
                'ulid' => $cuenta->ulid,
                'folio' => $cuenta->folioNumber(),
                'display_name' => $cuenta->displayName(),
                'items_count' => $cuenta->items_count,
                'pending_to_command' => (int) $cuenta->pending_to_command_count,
                'opened_at' => $cuenta->opened_at,
                'bill_requested_at' => $cuenta->bill_requested_at,
            ],
        ];
    }
}
