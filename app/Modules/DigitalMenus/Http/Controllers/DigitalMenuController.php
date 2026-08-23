<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Http\Controllers;

use App\Modules\DigitalMenus\Http\Requests\StoreDigitalMenuRequest;
use App\Modules\DigitalMenus\Http\Requests\UpdateDigitalMenuRequest;
use App\Modules\DigitalMenus\Http\Resources\DigitalMenuResource;
use App\Modules\DigitalMenus\Infrastructure\Models\DigitalMenu;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Menús digitales por sucursal (Iteración 8, Tanda A). Rutas gateadas por `module:DigitalMenus`: un negocio sin el módulo
 * no ejecuta esto (404). Gestionar exige `digital_menus.menus.manage`, y crear/editar un menú se acota a las sucursales que
 * el rol activo alcanza.
 */
final class DigitalMenuController
{
    use AssertsBranchScope;

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, DigitalMenu>>
     */
    public function index(): AnonymousResourceCollection
    {
        $menus = DigitalMenu::query()->with('branch')->orderBy('id')->get();

        return DigitalMenuResource::collection($menus);
    }

    public function store(StoreDigitalMenuRequest $request): JsonResponse
    {
        $branch = Branch::query()->where('ulid', (string) $request->string('branch_ulid'))->first()
            ?? throw new UnprocessableEntityHttpException('La sucursal no existe.');

        $this->assertBranchInScope((int) $branch->id);

        if (DigitalMenu::query()->where('branch_id', $branch->id)->exists()) {
            throw new UnprocessableEntityHttpException('Esa sucursal ya tiene un menú.');
        }

        $menu = DigitalMenu::create([
            'branch_id' => $branch->id,
            'slug' => (string) $request->string('slug'),
        ]);

        return new JsonResponse(['data' => new DigitalMenuResource($menu->load('branch'))], 201);
    }

    public function update(UpdateDigitalMenuRequest $request, DigitalMenu $digitalMenu): JsonResponse
    {
        $this->assertBranchInScope((int) $digitalMenu->branch_id);

        $digitalMenu->update($request->validated());

        return new JsonResponse(['data' => new DigitalMenuResource($digitalMenu->refresh()->load('branch'))]);
    }
}
