<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\ManageArticleBranchOverride;
use App\Modules\Catalog\Http\Requests\SetBranchAvailabilityRequest;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Overrides por sucursal: consulta y disponibilidad (§6.1).
 *
 * El **precio** por sucursal lo sirve `Costing`, por lo mismo que el precio maestro: historizarlo exige el
 * snapshot de costeo y `Catalog` no puede depender de `Costing` (D115). La **disponibilidad** sí vive aquí:
 * no es un precio, no lleva snapshot y su permiso es el de administrar el catálogo.
 */
final class ArticleBranchOverrideController
{
    public function __construct(private readonly ManageArticleBranchOverride $overrides) {}

    /**
     * Los overrides del artículo en todas las sucursales, con la cascada resuelta.
     *
     * Devuelve una entrada por override existente y no una por sucursal: la ausencia de fila ya significa "usa
     * el precio del negocio", y listar las cuarenta sucursales que no tienen nada propio sería ruido en el que
     * se pierden las tres que sí.
     */
    public function index(Article $article): JsonResponse
    {
        $overrides = $article->branchOverrides()->with('branch')->get();

        return new JsonResponse([
            'data' => [
                'article_ulid' => $article->ulid,

                // El dato maestro, para poder leer los overrides contra él sin una segunda llamada.
                'master_price' => $article->base_price,
                'master_is_available_in_pos' => $article->is_available_in_pos,

                'overrides' => $overrides->map(fn ($override): array => [
                    'branch' => [
                        'ulid' => $override->branch?->ulid,
                        'name' => $override->branch?->name,
                        'code' => $override->branch?->code,
                    ],

                    // NULL = hereda. La UI necesita distinguirlo de un valor propio igual al maestro: el día
                    // que cambie el precio del negocio, lo que hereda lo sigue y lo que tiene override no.
                    'price' => $override->price,
                    'is_available_in_pos' => $override->is_available_in_pos,
                ])->values(),
            ],
        ]);
    }

    /**
     * Fija o quita la disponibilidad propia de una sucursal.
     *
     * `null` vuelve a heredar. No se historiza en `price_changes` —no es un precio— y meterlo ahí ensuciaría
     * el historial que D15 define. La bitácora técnica sí lo registra, y basta: apagar un platillo en una
     * sucursal es reversible y no afecta a ningún documento pasado.
     */
    public function setAvailability(
        SetBranchAvailabilityRequest $request,
        Article $article,
        Branch $branch,
        ContextHolder $context,
    ): JsonResponse {
        self::assertBranchInScope($branch, $context);

        $override = $this->overrides->setAvailability(
            $article,
            $branch,
            $request->input('is_available_in_pos') === null ? null : $request->boolean('is_available_in_pos'),
        );

        return new JsonResponse([
            'data' => [
                'article_ulid' => $article->ulid,
                'branch_ulid' => $branch->ulid,

                // `null` en las dos = ya no hay override: la fila se borró porque heredaba todo.
                'price' => $override?->price,
                'is_available_in_pos' => $override?->is_available_in_pos,

                'effective_is_available_in_pos' => $override?->is_available_in_pos
                    ?? $article->is_available_in_pos,
            ],
        ]);
    }

    /**
     * La sucursal tiene que estar en el ALCANCE de quien opera.
     *
     * Sin esto, un gerente con alcance sobre una sola sucursal podría cambiar el precio y la disponibilidad de
     * otra: el `tenant_id` lo protege del negocio ajeno, pero no de la sucursal ajena dentro de su propio
     * negocio. Es exactamente el hueco que `membership_branch_scopes` existe para cerrar, y hay que cerrarlo
     * aquí porque el binding de ruta resuelve cualquier sucursal del tenant.
     *
     * Se comparte con el controlador de `Costing` —que sirve el override de precio— porque la regla es la
     * misma y duplicarla sería la forma de que una de las dos copias se quedara sin actualizar.
     */
    public static function assertBranchInScope(Branch $branch, ContextHolder $context): void
    {
        $membership = $context->getOrNull()?->membership;

        if ($membership === null || ! $membership->canOperateInBranch($branch->id)) {
            throw new HttpException(403, 'No tienes acceso a esa sucursal.');
        }
    }
}
