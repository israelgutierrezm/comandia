<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Http\Requests\SyncMembershipBranchScopesRequest;
use App\Modules\Identity\Http\Resources\MembershipResource;
use App\Modules\Identity\Infrastructure\Models\MembershipBranchScope;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * En qué sucursales opera una persona.
 *
 * ## Este endpoint faltaba, y el permiso ya existía
 *
 * `identity.memberships.manage_branch_scopes` está en el catálogo cerrado desde la Iteración 1 —«Definir
 * en qué sucursales opera cada persona»— y **ninguna ruta lo usaba**. Un tenant podía marcar la casilla en
 * un rol y no pasaba nada: el alcance sólo se podía fijar al dar de alta a la persona, y después no había
 * forma de cambiarlo salvo por la base de datos.
 *
 * Es el fallo inverso al que vigila el candado de rutas: ése encuentra rutas que piden permisos
 * inexistentes; éste era un permiso sin ruta. No se puede convertir en candado todavía porque el catálogo
 * declara a propósito permisos de iteraciones que no existen (punto de venta, inventarios), y exigir que
 * cada uno tenga endpoint haría fallar la suite por lo que aún no se ha construido.
 *
 * ## Permiso propio, y no el de editar datos
 *
 * Igual que la asignación de roles. Cambiarle el nombre a alguien y decidir en qué sucursales opera son
 * cosas de naturaleza distinta: la segunda decide dónde puede cobrar, y en un negocio con varias
 * sucursales es la diferencia entre auditar bien y no poder auditar.
 *
 * ## Se sincroniza la lista completa
 *
 * Por lo mismo que los roles y las recetas: con operaciones de agregar y quitar, dos peticiones
 * simultáneas dejarían un alcance que no es ninguno de los dos que se pidieron.
 */
final class MembershipBranchScopeController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function sync(
        SyncMembershipBranchScopesRequest $request,
        TenantMembership $membership,
    ): MembershipResource {
        $todas = $request->boolean('has_all_branches');

        /** @var list<string> $ulids */
        $ulids = $todas ? [] : array_values(array_unique((array) $request->input('branch_ulids', [])));

        $membership->load('branchScopes.branch');

        $antes = [
            'has_all_branches' => $membership->has_all_branches,
            'branches' => $membership->branchScopes
                ->map(fn (MembershipBranchScope $scope): ?string => $scope->branch?->code)
                ->filter()
                ->values()
                ->all(),
        ];

        // Se resuelven con el scope de tenant aplicado, así que un ULID de otro negocio simplemente no
        // aparece: el aislamiento no depende de que el cliente mande identificadores válidos.
        $branches = Branch::query()->whereIn('ulid', $ulids)->get();

        DB::transaction(function () use ($membership, $branches, $todas): void {
            // Se borra y se vuelve a escribir. Es correcto porque `membership_branch_scopes` no es una
            // tabla de historial: no tiene `updated_at` ni nada que preservar — una fila es la afirmación
            // «esta persona opera aquí», y quitarla es la afirmación contraria. El rastro de qué cambió y
            // quién lo cambió vive en la bitácora, que sí es inmutable.
            $membership->branchScopes()->delete();

            foreach ($branches as $branch) {
                $membership->branchScopes()->create(['branch_id' => $branch->id]);
            }

            $membership->update(['has_all_branches' => $todas]);
        });

        $this->audit->log(
            action: AuditAction::BRANCH_SCOPES_UPDATED,
            auditable: $membership,
            before: $antes,
            after: [
                'has_all_branches' => $todas,
                'branches' => $branches->pluck('code')->all(),
            ],
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
        );
    }
}
