<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseCategory
 */
final class ExpenseCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,

            // A diferencia de un método de pago, una categoría del sistema SÍ se renombra: su nombre es una etiqueta de
            // reporte que el negocio ajusta a su vocabulario —«Luz» o «CFE»—, no la referencia con la que el diario
            // agrupa el dinero. Lo que no se puede es borrarla.
            'can_be_deleted' => ! $this->is_system,
        ];
    }
}
