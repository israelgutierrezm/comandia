<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\SavedReportView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una vista guardada, con sus parámetros reconstruidos a la forma que la pantalla vuelve a enviar: `group_by` como lista
 * y el resto de filtros como pares.
 *
 * @mixin SavedReportView
 */
final class SavedReportViewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $params = $this->params;

        $groupBy = $params->where('name', 'group_by')->pluck('value')->values()->all();

        $filters = $params->where('name', '!=', 'group_by')
            ->mapWithKeys(fn ($p): array => [$p->name => $p->value])
            ->all();

        return [
            'ulid' => $this->ulid,
            'report_key' => $this->report_key,
            'name' => $this->name,
            'group_by' => $groupBy,
            'filters' => $filters,
        ];
    }
}
