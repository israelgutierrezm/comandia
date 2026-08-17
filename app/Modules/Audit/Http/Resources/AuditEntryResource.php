<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditEntry
 */
final class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'action' => $this->action,

            // ---------------------------------------------------------------
            // Los dos actores, separados. Es la razón de ser de esta tabla: sin la
            // distinción, "el gerente aplicó el descuento" y "el gerente autorizó que el
            // mesero lo aplicara" serían el mismo registro, y el reporte de robo hormiga
            // que exige §9 no podría existir.
            // ---------------------------------------------------------------
            'actor' => $this->describeMembership($this->actor),
            'authorized_by' => $this->describeMembership($this->authorizedBy),
            'was_authorized_by_another' => $this->wasAuthorizedByAnother(),

            // Con D9 el permiso efectivo depende del rol activo: sin él, "¿podía hacerlo?"
            // no tiene respuesta reproducible.
            'active_role' => $this->whenLoaded('activeRole', fn () => $this->activeRole === null ? null : [
                'ulid' => $this->activeRole->ulid,
                'name' => $this->activeRole->name,
            ]),

            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'terminal' => $this->whenLoaded('terminal', fn () => $this->terminal === null ? null : [
                'ulid' => $this->terminal->ulid,
                'name' => $this->terminal->name,
            ]),

            // Tipo corto y no el FQCN: el cliente no tiene por qué conocer los namespaces
            // internos, y exponerlos filtra la estructura del código.
            'auditable' => $this->auditable_type === null ? null : [
                'type' => class_basename($this->auditable_type),
                'id' => $this->auditable_id,
            ],

            'before' => $this->before,
            'after' => $this->after,

            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,

            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeMembership(?TenantMembership $membership): ?array
    {
        if ($membership === null) {
            // Acción del sistema —un job, el scheduler— y no de una persona. Devolver null y no
            // un nombre inventado como "Sistema": el cliente decide cómo presentarlo, y un
            // nombre falso en la bitácora sería indistinguible de un actor real llamado así.
            return null;
        }

        return [
            'ulid' => $membership->ulid,
            'name' => app(MembershipNameResolver::class)->resolve($membership)->short(),
            'employee_code' => $membership->employee_code,
        ];
    }
}
