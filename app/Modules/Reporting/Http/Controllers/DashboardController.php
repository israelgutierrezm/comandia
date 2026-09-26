<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Reporting\Http\Requests\StoreDashboardRequest;
use App\Modules\Reporting\Http\Requests\StoreWidgetRequest;
use App\Modules\Reporting\Http\Requests\UpdateDashboardRequest;
use App\Modules\Reporting\Http\Resources\DashboardResource;
use App\Modules\Reporting\Http\Resources\DashboardWidgetResource;
use App\Modules\Reporting\Infrastructure\Models\Dashboard;
use App\Modules\Reporting\Infrastructure\Models\DashboardWidget;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tableros y sus widgets (Tanda C, D46).
 *
 * Ver un tablero exige `dashboards.dashboards.view`; construirlos, `.manage`; publicarlos a un rol, `.publish`. Además,
 * editar/borrar está acotado al AUTOR. Un tablero se ve si es tuyo o si está publicado a tu rol activo. Los widgets no
 * declaran permiso: cada uno hereda el de su reporte cuando la pantalla lo pinta (ADR-006).
 */
final class DashboardController
{
    public function __construct(
        private readonly ContextHolder $context,
        private readonly Authorize $authorize,
    ) {}

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, Dashboard>>
     */
    public function index(): AnonymousResourceCollection
    {
        $dashboards = Dashboard::query()
            ->where(fn ($q) => $q
                ->where('membership_id', $this->membershipId())
                ->orWhere('published_role_id', $this->activeRoleId()))
            ->with('widgets')
            ->orderBy('name')
            ->get();

        return DashboardResource::collection($dashboards);
    }

    public function store(StoreDashboardRequest $request): JsonResponse
    {
        $dashboard = Dashboard::create([
            'membership_id' => $this->membershipId(),
            'name' => (string) $request->string('name'),
        ]);

        return new JsonResponse(['data' => new DashboardResource($dashboard->load('widgets'))], 201);
    }

    public function show(Dashboard $dashboard): JsonResponse
    {
        $this->assertVisible($dashboard);

        return new JsonResponse(['data' => new DashboardResource($dashboard->load('widgets'))]);
    }

    public function update(UpdateDashboardRequest $request, Dashboard $dashboard): JsonResponse
    {
        $this->assertOwner($dashboard);

        /** @var array{name?: string, published_role_ulid?: string|null} $validado */
        $validado = $request->validated();

        if (array_key_exists('name', $validado)) {
            $dashboard->name = $validado['name'];
        }

        // Publicar (o despublicar) es una acción aparte con su propio permiso: se exige en cuanto el cuerpo TRAE el
        // campo, aunque no cambie.
        if (array_key_exists('published_role_ulid', $validado)) {
            $this->authorize->authorize('dashboards.dashboards.publish');

            $ulid = $validado['published_role_ulid'];

            // El Form Request ya comprobó que el rol es de este negocio. `valueOrFail` y no `value`: si desapareciera
            // entre la validación y aquí, falla en voz alta en lugar de despublicar en silencio, que era el defecto.
            $dashboard->published_role_id = $ulid === null
                ? null
                : (int) Role::query()->where('ulid', $ulid)->valueOrFail('id');
        }

        $dashboard->save();

        return new JsonResponse(['data' => new DashboardResource($dashboard->load('widgets'))]);
    }

    public function destroy(Dashboard $dashboard): JsonResponse
    {
        $this->assertOwner($dashboard);
        $dashboard->delete();

        return new JsonResponse(status: 204);
    }

    public function addWidget(StoreWidgetRequest $request, Dashboard $dashboard): JsonResponse
    {
        $this->assertOwner($dashboard);

        $widget = $dashboard->widgets()->create([
            'report_key' => (string) $request->string('report_key'),
            'visualization' => (string) $request->string('visualization'),
            'title' => (string) $request->string('title'),
            'measure_key' => $request->input('measure_key'),
            'dimension_key' => $request->input('dimension_key'),
            'period' => $request->input('period'),
            'top_n' => $request->input('top_n'),
            'position' => (int) $request->input('position', $dashboard->widgets()->max('position') + 1),
        ]);

        return new JsonResponse(['data' => new DashboardWidgetResource($widget)], 201);
    }

    public function removeWidget(DashboardWidget $dashboardWidget): JsonResponse
    {
        // El widget se borra si el tablero es del autor.
        $this->assertOwner($dashboardWidget->dashboard);
        $dashboardWidget->delete();

        return new JsonResponse(status: 204);
    }

    private function assertVisible(Dashboard $dashboard): void
    {
        $mine = (int) $dashboard->membership_id === $this->membershipId();
        $published = $dashboard->published_role_id !== null && (int) $dashboard->published_role_id === $this->activeRoleId();

        if (! $mine && ! $published) {
            abort(404);
        }
    }

    private function assertOwner(Dashboard $dashboard): void
    {
        if ((int) $dashboard->membership_id !== $this->membershipId()) {
            abort(404);
        }
    }

    private function membershipId(): int
    {
        return (int) $this->context->get()->requireMembership()->id;
    }

    private function activeRoleId(): int
    {
        return (int) $this->context->get()->requireActiveRole()->id;
    }
}
