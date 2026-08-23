<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Application\AssembleStore;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La tienda pública (Iteración 8, Tanda B): `/t/{slug}`, sin autenticación. El slug resuelve el negocio; el cliente elige
 * entre las sucursales que la tienda atiende y ve el catálogo con precio y stock de esa sucursal.
 */
final class PublicStoreController
{
    use ResolvesPublicStore;

    public function __construct(private readonly AssembleStore $assembler) {}

    public function show(string $slug): View
    {
        $store = $this->resolveStore($slug);

        // Meta y branches server-side (SEO + primer render); el catálogo y el carrito los pide la SPA.
        return view('store.public', [
            'store' => [
                'slug' => $store->slug,
                'name' => $store->name,
                'theme' => ['primary' => $store->theme_primary],
                'branches' => $this->servedBranches($store),
            ],
        ]);
    }

    public function catalog(Request $request, string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);
        $branches = $this->servedBranches($store);

        // La sucursal pedida, o la primera que atiende la tienda.
        $branchUlid = (string) $request->query('branch', $branches[0]['ulid'] ?? '');
        $selected = collect($branches)->firstWhere('ulid', $branchUlid) ?? ($branches[0] ?? null);

        if ($selected === null) {
            throw new NotFoundHttpException('La tienda no atiende ninguna sucursal.');
        }

        $branchId = $this->branchIdByUlid($store, $selected['ulid']);

        return new JsonResponse([
            'data' => [
                'store' => ['name' => $store->name, 'theme' => ['primary' => $store->theme_primary]],
                'branches' => $branches,
                'selected_branch' => $selected['ulid'],
                'catalog' => $branchId === null ? [] : $this->assembler->catalogFor($store, $branchId),
            ],
        ]);
    }

    /**
     * @return list<array{ulid: string, name: string}>
     */
    private function servedBranches($store): array
    {
        return $store->storeBranches()->with('branch')->get()
            ->map(fn ($sb) => $sb->branch === null ? null : ['ulid' => $sb->branch->ulid, 'name' => $sb->branch->name])
            ->filter()
            ->values()
            ->all();
    }

    private function branchIdByUlid($store, string $branchUlid): ?int
    {
        $sb = $store->storeBranches()->with('branch')->get()->first(fn ($sb) => $sb->branch?->ulid === $branchUlid);

        return $sb === null ? null : (int) $sb->branch_id;
    }
}
