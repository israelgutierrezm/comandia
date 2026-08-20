<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\CalculateAvailableTips;
use App\Modules\Finance\Application\SettleTips;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liquidación de propinas (§6.6).
 *
 * ## La pantalla es «a quién le debo», no «quién ha tenido propinas»
 *
 * `pending` lista sólo a quien tiene saldo por cobrar. Incluir a los que ya están al corriente con un cero llenaría la
 * lista de gente que no hay que pagar, y en un turno con quince meseros eso vuelve la pantalla inútil.
 */
final class TipSettlementController
{
    use AssertsBranchScope;

    public function __construct(
        private readonly CalculateAvailableTips $tips,
        private readonly SettleTips $settlements,
    ) {}

    /**
     * A quién se le debe propina y cuánto.
     */
    public function pending(): JsonResponse
    {
        $pendientes = array_map(
            fn (array $fila): array => [
                'membership' => [
                    'ulid' => $fila['membership']->ulid,
                    'name' => app(MembershipNameResolver::class)->resolve($fila['membership'])->short(),
                    'employee_code' => $fila['membership']->employee_code,
                ],
                'available' => $fila['available'],
            ],
            $this->tips->pending(),
        );

        return new JsonResponse(['data' => $pendientes]);
    }

    /**
     * Entregarle a alguien su propina.
     */
    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'membership_ulid' => ['required', 'string', 'size:26'],
            'branch_ulid' => ['required', 'string', 'size:26'],

            // El monto lo manda quien liquida —puede entregar una parte— y el servidor comprueba que no pase del
            // disponible, recalculado dentro de la transacción.
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
        ]);

        $sucursal = Branch::query()->where('ulid', $validado['branch_ulid'])->sole();

        // Liquidar propinas es sacar efectivo del cajón. La sucursal decide de QUÉ cajón sale.
        $this->assertBranchInScope((int) $sucursal->id);

        $liquidacion = $this->settlements->settle(
            member: TenantMembership::query()->where('ulid', $validado['membership_ulid'])->sole(),
            amount: $validado['amount'],
            branch: $sucursal,
        );

        return new JsonResponse([
            'data' => [
                'ulid' => $liquidacion->ulid,
                'amount' => $liquidacion->amount,
                'created_at' => $liquidacion->created_at?->toIso8601String(),

                // Lo que le queda por cobrar después de esto: es lo que la pantalla necesita para actualizarse sin
                // volver a pedir la lista entera.
                'remaining' => $this->tips->forMembership((int) $liquidacion->membership_id),
            ],
        ], 201);
    }
}
