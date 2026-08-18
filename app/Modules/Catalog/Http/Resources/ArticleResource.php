<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Article
 *
 * ## Este recurso NO trae el costo, ni el precio sugerido, ni el margen
 *
 * Y no es un olvido: es la consecuencia directa de P1. El costo pertenece al módulo `Costing`, y
 * `Catalog` no puede depender de `Costing` —el candado de fronteras lo rechazaría—. Los expone
 * `Costing` en `GET /api/v1/articles/{ulid}/cost`.
 *
 * El costo de esa decisión es concreto y hay que decirlo: una pantalla que muestre catálogo **con**
 * costo hace dos llamadas. La alternativa era que `Catalog` conociera a `Costing`, y entonces el
 * grafo de módulos tendría un ciclo el día que `Costing` necesite escribir un precio sugerido — que
 * es exactamente el día siguiente.
 */
final class ArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'short_name' => $this->short_name,

            // Lo que se imprime en comanda y en el botón del POS, ya resuelto: si el cliente
            // aplicara la regla de "corto o largo" por su cuenta, tendría una copia de la regla.
            'display_name' => $this->displayName(),

            // Las cuatro capacidades de D17. Van agrupadas y no como cuatro banderas sueltas porque
            // son un conjunto con significado, y verlas juntas es lo que hace evidente que una
            // cerveza puede ser vendible e insumo a la vez.
            'capabilities' => $this->capabilities(),

            // Lotes y caducidades (D23). FUERA de `capabilities` a propósito: las cuatro capacidades dicen qué
            // ES el artículo; ésta dice cómo se controla la existencia de algo que ya se decidió inventariar.
            'tracks_lots' => $this->tracks_lots,

            'base_price' => $this->base_price,
            'markup_percent' => $this->markup_percent,
            'is_available_in_pos' => $this->is_available_in_pos,
            'status' => $this->status->value,

            // El precio y la disponibilidad que aplican en una sucursal, con la cascada de §6.1 resuelta.
            //
            // Sólo cuando la petición pidió una sucursal: sin ella, el recurso describe el dato maestro del
            // negocio, que es lo que la administración del catálogo edita. El identificador de la sucursal
            // viaja en los atributos de la petición porque el controlador ya lo resolvió — resolverlo aquí
            // costaría una consulta por fila del listado.
            ...$this->effectiveFor($request),

            'base_unit' => $this->whenLoaded('baseUnit', fn () => [
                'ulid' => $this->baseUnit->ulid,
                'code' => $this->baseUnit->code,
                'name' => $this->baseUnit->name,
                'dimension' => $this->baseUnit->dimension->value,
            ]),

            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'ulid' => $this->category->ulid,
                'name' => $this->category->name,
                'level' => $this->category->level,
            ]),

            'tags' => $this->whenLoaded(
                'tags',
                fn () => $this->tags->map(fn ($tag): array => [
                    'ulid' => $tag->ulid,
                    'name' => $tag->name,
                ])->values(),
            ),

            'presentations' => ArticlePresentationResource::collection(
                $this->whenLoaded('purchasePresentations')
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Los valores efectivos en la sucursal pedida, o nada si no se pidió ninguna.
     *
     * `*_is_overridden` viaja junto al valor porque la UI necesita distinguir "hereda $85" de "esta sucursal
     * decidió $85": el día que cambie el precio del negocio, el primero lo sigue y el segundo no. Es la misma
     * distinción que la configuración jerárquica hace entre heredar y estar configurado aquí.
     *
     * @return array<string, mixed>
     */
    private function effectiveFor(Request $request): array
    {
        $branchId = $request->attributes->get('effective_branch_id');

        if (! is_int($branchId)) {
            return [];
        }

        $pricing = $this->effectivePricingFor($branchId);

        return [
            'effective_price' => $pricing->price,
            'effective_price_is_overridden' => $pricing->priceIsOverridden,
            'effective_is_available_in_pos' => $pricing->isAvailableInPos,
            'effective_availability_is_overridden' => $pricing->availabilityIsOverridden,
        ];
    }
}
