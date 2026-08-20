<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Pos\Domain\Enums\PosSessionStatus;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Pos\Infrastructure\Models\PosSessionDeclaration;
use App\Modules\Pos\Infrastructure\Models\PosSessionWithdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosSession
 *
 * Una sesión de caja. **No lleva el arqueo**: el esperado y la diferencia se calculan del diario (§6.5, ADR-004) y
 * llegan con el corte en el paso 19. Publicarlos aquí exigiría sumar el diario en cada listado de turnos, que es la
 * consulta que más veces corre en la pantalla del gerente.
 */
final class PosSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'folio' => $this->folioNumber(),
            'series' => $this->series,
            'folio_number' => $this->folio,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->isOpen(),

            // Las transiciones las decide el SERVIDOR. Es la lección de D139: si el cliente las deduce, acaba con su
            // propia copia de la máquina de estados y se desincroniza en la primera iteración que añada un paso.
            'allowed_next' => array_map(
                fn (PosSessionStatus $s): string => $s->value,
                $this->status->allowedNext(),
            ),

            'opening_float' => $this->opening_float,

            'terminal' => $this->whenLoaded('terminal', fn () => [
                'ulid' => $this->terminal->ulid,
                'code' => $this->terminal->code,
                'name' => $this->terminal->name,
            ]),

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            // Quién hizo cada cosa. En un cambio de turno, quien cierra puede no ser quien abrió, y saber quién dijo qué
            // es la mitad del valor de un arqueo.
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->person($this->openedBy)),
            'precounted_by' => $this->whenLoaded('precountedBy', fn () => $this->person($this->precountedBy)),
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->person($this->closedBy)),

            'opened_at' => $this->opened_at?->toIso8601String(),
            'precounted_at' => $this->precounted_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),

            'closing_notes' => $this->closing_notes,

            'declarations' => $this->whenLoaded(
                'declarations',
                fn () => $this->declarations->map(fn (PosSessionDeclaration $d): array => [
                    'ulid' => $d->ulid,
                    'moment' => $d->moment,
                    'declared_amount' => $d->declared_amount,
                    'payment_method' => $d->paymentMethod === null ? null : [
                        'ulid' => $d->paymentMethod->ulid,
                        'code' => $d->paymentMethod->code,
                        'name' => $d->paymentMethod->name,
                        'affects_cash_drawer' => $d->paymentMethod->affects_cash_drawer,
                    ],
                ])->all(),
            ),

            'withdrawals' => $this->whenLoaded(
                'withdrawals',
                fn () => $this->withdrawals->map(fn (PosSessionWithdrawal $w): array => [
                    'ulid' => $w->ulid,
                    'amount' => $w->amount,
                    'reason' => $w->reason,
                    'created_at' => $w->created_at?->toIso8601String(),
                ])->all(),
            ),

            'withdrawals_total' => $this->whenLoaded(
                'withdrawals',
                // Se suma en el servidor porque es dinero: sumar decimales en JavaScript es donde se cuelan los errores
                // de redondeo (D134).
                fn (): string => (string) $this->withdrawals->reduce(
                    fn (string $carry, PosSessionWithdrawal $w): string => bcadd($carry, (string) $w->amount, 2),
                    '0.00',
                ),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function person(mixed $membership): ?array
    {
        if (! $membership instanceof TenantMembership) {
            return null;
        }

        return [
            'ulid' => $membership->ulid,
            'name' => app(MembershipNameResolver::class)->resolve($membership)->short(),
            'employee_code' => $membership->employee_code,
        ];
    }
}
