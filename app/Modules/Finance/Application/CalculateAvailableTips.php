<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\TipSettlement;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * Cuánta propina se le debe a cada persona.
 *
 * ## Se CALCULA, no se almacena
 *
 * Misma decisión que el corte (§6.5, ADR-004) y por la misma razón: una cifra almacenada como verdad paralela se desvía
 * —una liquidación que falla a medias, un ajuste— y después nadie sabe cuál era la buena. Aquí se reconstruye siempre:
 * lo que le corresponde menos lo ya liquidado.
 *
 * ## Y se calcula DEL DIARIO, no de los pagos
 *
 * Es donde una decisión del paso 10 termina de pagarse. Al asentar la propina de cada línea de pago puse como actor a
 * **quien se le atribuye** la propina y no a quien cobró, con el argumento de que «permitirá agrupar por persona
 * directamente del diario». Es exactamente esto.
 *
 * La alternativa era leer `pos_payments` agrupado por `tip_membership_id`, que es lo que el diseño describía en §6.6 — y
 * habría cerrado un ciclo `Finance → Pos`. Sin aquella decisión, este paso habría exigido un tercer contrato de pregunta
 * en el kernel; con ella, el dato ya estaba donde hacía falta.
 *
 * ## Sólo la propina COBRADA cuenta
 *
 * Una propina que el cliente dejó en una cuenta que después se revirtió no se le debe a nadie. El diario ya lo resuelve
 * solo: la reversa de un pago lleva el mismo tipo con signo contrario, así que la suma la descuenta sin que este
 * servicio tenga que saber de reversas.
 */
final readonly class CalculateAvailableTips
{
    /**
     * Lo que se le debe a una persona.
     *
     * @return numeric-string nunca negativo
     */
    public function forMembership(int $membershipId): string
    {
        $ganado = (string) (FinancialMovement::query()
            ->where('type', FinancialMovementType::Tip->value)
            ->where('actor_membership_id', $membershipId)
            ->sum('amount') ?? 0);

        $liquidado = (string) (TipSettlement::query()
            ->where('membership_id', $membershipId)
            ->sum('amount') ?? 0);

        $disponible = bcsub(Decimal::round($ganado, 2), Decimal::round($liquidado, 2), 2);

        // Nunca negativo hacia fuera. Puede quedar en negativo si se liquidó de más —un error que se corrige con otra
        // liquidación en contra, no editando ésta— y «menos doscientos por pagar» no significa nada para quien lo lee.
        return bccomp($disponible, '0', 2) < 0 ? '0.00' : $disponible;
    }

    /**
     * Lo que se le debe a cada quien, para la pantalla de liquidación.
     *
     * @return list<array{membership: TenantMembership, available: numeric-string}>
     */
    public function pending(): array
    {
        // Se parte de quién tiene propinas asentadas, no de toda la plantilla: un lavaloza que nunca atendió una mesa no
        // debería aparecer en la lista con cero.
        $ganadoPorPersona = FinancialMovement::query()
            ->where('type', FinancialMovementType::Tip->value)
            ->selectRaw('actor_membership_id, SUM(amount) as total')
            ->groupBy('actor_membership_id')
            ->pluck('total', 'actor_membership_id');

        if ($ganadoPorPersona->isEmpty()) {
            return [];
        }

        $liquidadoPorPersona = TipSettlement::query()
            ->selectRaw('membership_id, SUM(amount) as total')
            ->groupBy('membership_id')
            ->pluck('total', 'membership_id');

        $personas = TenantMembership::query()
            ->whereIn('id', $ganadoPorPersona->keys())
            ->with(['user', 'employeeProfile'])
            ->get()
            ->keyBy('id');

        $pendientes = [];

        foreach ($ganadoPorPersona as $membershipId => $ganado) {
            $disponible = bcsub(
                Decimal::round((string) $ganado, 2),
                Decimal::round((string) ($liquidadoPorPersona[$membershipId] ?? '0'), 2),
                2,
            );

            // Los que ya están al corriente no se listan: la pantalla es «a quién le debo», no «quién ha tenido
            // propinas alguna vez».
            if (bccomp($disponible, '0', 2) <= 0 || ! $personas->has($membershipId)) {
                continue;
            }

            $pendientes[] = [
                'membership' => $personas[$membershipId],
                'available' => $disponible,
            ];
        }

        return $pendientes;
    }
}
