<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Http\Requests\StoreSavedViewRequest;
use App\Modules\Reporting\Http\Resources\SavedReportViewResource;
use App\Modules\Reporting\Infrastructure\Models\SavedReportView;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Vistas guardadas de reporte, por usuario (Tanda B, D45).
 *
 * Guardar/borrar exige `reporting.saved_views.manage`; además, todo aquí está acotado al AUTOR (su membresía): una vista
 * es personal. Ejecutar la vista es volver a pedir el reporte con sus parámetros, y ahí el motor verifica el permiso del
 * reporte — guardar la vista no otorga acceso a datos.
 */
final class SavedReportViewController
{
    public function __construct(
        private readonly ContextHolder $context,
    ) {}

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, SavedReportView>>
     */
    public function index(string $report): AnonymousResourceCollection
    {
        $views = SavedReportView::query()
            ->where('membership_id', $this->membershipId())
            ->where('report_key', $report)
            ->with('params')
            ->orderBy('name')
            ->get();

        return SavedReportViewResource::collection($views);
    }

    public function store(StoreSavedViewRequest $request, string $report): JsonResponse
    {
        $view = DB::transaction(function () use ($request, $report): SavedReportView {
            $view = SavedReportView::create([
                'membership_id' => $this->membershipId(),
                'report_key' => $report,
                'name' => (string) $request->string('name'),
            ]);

            foreach ($request->except('name') as $name => $value) {
                if ($name === 'group_by') {
                    // La agrupación se guarda como una fila por dimensión (normalizado, sin cadenas delimitadas).
                    $groups = is_array($value) ? $value : explode(',', (string) $value);

                    foreach (array_filter($groups) as $dimension) {
                        $view->params()->create(['name' => 'group_by', 'value' => (string) $dimension]);
                    }

                    continue;
                }

                $view->params()->create(['name' => $name, 'value' => $value === null ? null : (string) $value]);
            }

            return $view;
        });

        return new JsonResponse(['data' => new SavedReportViewResource($view->load('params'))], 201);
    }

    public function destroy(SavedReportView $savedReportView): JsonResponse
    {
        // Sólo el autor borra su vista; una ajena responde 404 (el tenant scope ya acota; esto acota por membresía).
        if ((int) $savedReportView->membership_id !== $this->membershipId()) {
            abort(404);
        }

        $savedReportView->delete();

        return new JsonResponse(status: 204);
    }

    private function membershipId(): int
    {
        return (int) $this->context->get()->requireMembership()->id;
    }
}
