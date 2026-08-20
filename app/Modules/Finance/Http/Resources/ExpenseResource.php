<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Infrastructure\Models\Expense;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
final class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'amount' => $this->amount,
            'description' => $this->description,

            'source' => $this->source->value,
            'source_label' => $this->source->label(),

            // Si toca el arqueo o no, resuelto en el servidor: es la distinción que hace que el corte del cajero no
            // cargue con la renta del local, y la pantalla no debería deducirla del valor del enum.
            'affects_cash_drawer' => $this->source->affectsCashDrawer(),

            'category' => $this->whenLoaded('category', fn () => [
                'ulid' => $this->category->ulid,
                'name' => $this->category->name,
            ]),

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'payment_method' => $this->whenLoaded('method', fn () => $this->method === null ? null : [
                'ulid' => $this->method->ulid,
                'name' => $this->method->name,
            ]),

            'receipt_path' => $this->receipt_path,

            // Las dos personas, por separado: quien lo registró y quien lo autorizó por encima del umbral.
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->person($this->createdBy)),
            'authorized_by' => $this->whenLoaded('authorizedBy', fn () => $this->person($this->authorizedBy)),

            'occurred_at' => $this->occurred_at?->toIso8601String(),
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
