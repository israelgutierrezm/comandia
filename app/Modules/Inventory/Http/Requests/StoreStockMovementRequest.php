<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cuerpo común de una entrada, una salida o un ajuste de inventario.
 *
 * Los tres endpoints comparten campos y validación; lo que cambia es **el tipo de movimiento y el permiso**, y
 * eso lo decide la ruta, no el cuerpo. Es la razón de que haya tres endpoints y no uno con un campo `kind`:
 *
 *   - El catálogo de permisos distingue `entries`, `exits` y `adjustments` (D10, cerrado). Con un endpoint
 *     único no habría forma de exigir el permiso correcto: `can:` recibe UN permiso, y decidirlo leyendo el
 *     cuerpo dejaría la ruta sin permiso declarado — o sea, invisible para el candado de D129.
 *   - Un `kind` libre en el cuerpo permitiría registrar un `sale_consumption` a mano, y ése tiene que venir
 *     del POS con su cuenta como origen. Los tipos que pertenecen a un documento no se capturan a mano.
 *
 * La subclase de cada endpoint declara su tipo. Aquí sólo vive lo que comparten.
 */
abstract class StoreStockMovementRequest extends FormRequest
{
    /** El tipo de movimiento que este endpoint registra. */
    abstract public function movementKind(): StockMovementKind;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'warehouse_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('warehouses', 'ulid')->where('tenant_id', $tenantId),
            ],

            'article_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('articles', 'ulid')->where('tenant_id', $tenantId),
            ],

            'lot_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('article_lots', 'ulid')->where('tenant_id', $tenantId),
            ],

            // En la unidad BASE del artículo, y siempre positiva: la dirección la decide el tipo del
            // endpoint. Cuatro decimales, la escala de la columna.
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            // Opcional: si no viene, se valúa al costo vigente del artículo (D152). Mandarlo tiene sentido en
            // una carga inicial, donde el costo histórico puede no ser el de hoy.
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],

            // Sólo los ajustes la aceptan; en entradas y salidas la dirección es del tipo y mandarla sería
            // pedirle al servidor que contradiga su propio endpoint.
            'direction' => [
                $this->movementKind()->fixedDirection() === null ? 'required' : 'prohibited',
                Rule::enum(StockMovementDirection::class),
            ],

            'notes' => [
                // Obligatoria en el ajuste: un descuadre sin explicación es lo que vuelve un inventario poco
                // creíble, y meses después nadie puede reconstruir si fue robo, error o merma no registrada.
                $this->movementKind()->requiresNotes() ? 'required' : 'nullable',
                'string', 'max:200',
            ],

            // Cuándo OCURRIÓ, que puede no ser cuándo se captura: una carga inicial se registra con la fecha
            // del inventario físico. No se admite el futuro — un movimiento que aún no pasó no tiene saldo.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // El lote tiene que ser del artículo. El dominio lo vuelve a comprobar —es un invariante del
                // kardex, no una regla de este formulario— pero aquí el mensaje sale por campo y con nombres.
                if (! $this->filled('lot_ulid')) {
                    return;
                }

                $lotBelongs = ArticleLot::query()
                    ->where('ulid', $this->string('lot_ulid')->toString())
                    ->whereHas('article', fn ($q) => $q->where('ulid', $this->string('article_ulid')->toString()))
                    ->exists();

                if (! $lotBelongs) {
                    $validator->errors()->add(
                        'lot_ulid',
                        'Ese lote no es de este artículo. Mezclarlos sumaría dos existencias distintas bajo '.
                        'el mismo saldo.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.gt' => 'La cantidad tiene que ser mayor que cero: para restar existencia se registra '.
                'una salida, no una cantidad negativa.',
            'quantity.decimal' => 'La cantidad admite hasta cuatro decimales.',
            'direction.required' => 'Indica si el ajuste suma o resta: en un ajuste, el signo es la '.
                'información y no hay valor por omisión razonable.',
            'direction.prohibited' => 'La dirección de este movimiento la decide su tipo y no se envía.',
            'notes.required' => 'Escribe por qué se ajusta. Un descuadre sin explicación no se puede '.
                'investigar después.',
            'occurred_at.before_or_equal' => 'La fecha no puede estar en el futuro.',
            'warehouse_ulid.exists' => 'Ese almacén no existe.',
            'article_ulid.exists' => 'Ese artículo no existe.',
            'lot_ulid.exists' => 'Ese lote no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_ulid' => 'el almacén',
            'article_ulid' => 'el artículo',
            'lot_ulid' => 'el lote',
            'quantity' => 'la cantidad',
            'unit_cost' => 'el costo unitario',
            'direction' => 'la dirección',
            'notes' => 'las notas',
            'occurred_at' => 'la fecha',
        ];
    }
}
