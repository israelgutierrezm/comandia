<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Escritura de la bitácora técnica (§6.7).
 *
 * Toma del contexto todo lo que puede tomar —tenant, sucursal, terminal, actor, rol
 * activo, IP, agente— para que quien registra una acción sólo tenga que decir **qué pasó**.
 * Si cada llamador tuviera que armar la fila completa, tarde o temprano alguien omitiría
 * el rol activo o el actor, y una fila de auditoría incompleta no sirve para lo que la
 * bitácora existe.
 *
 * ## Escritura síncrona, por ahora
 *
 * El diseño prevé que la auditoría se escriba en la cola `default` (y con eso justifica
 * los cuatro índices de consulta de la tabla). Hoy se escribe síncronamente, a propósito:
 * las acciones auditadas del kernel son de baja frecuencia —logins, cambios de
 * configuración, autorizaciones por PIN— y una escritura síncrona es una garantía y no un
 * riesgo. El job llega cuando aparezca el volumen del POS, y podrá envolver a este
 * servicio sin cambiar un solo llamador.
 *
 * Deuda declarada, no silenciosa.
 */
final readonly class AuditLogger
{
    public function __construct(
        private ContextHolder $holder,
        private Request $request,
    ) {}

    /**
     * Registra una acción.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $before = null,
        ?array $after = null,
        ?TenantMembership $authorizedBy = null,
    ): AuditEntry {
        $context = $this->holder->getOrNull();

        return AuditEntry::create([
            'branch_id' => $context?->activeBranch?->id,
            'terminal_id' => $context?->terminal?->id,

            // La evidencia duradera: su FK es RESTRICT, así que no se puede borrar a un
            // usuario que tiene historia.
            'actor_user_id' => $context?->user?->id,
            'actor_membership_id' => $context?->membership?->id,

            // El actor REAL de una acción sensible, distinto de quien la ejecuta
            // (ADR-008). Es la columna que hace posible el reporte de robo hormiga.
            'authorized_by_membership_id' => $authorizedBy?->id,

            // Con D9 el permiso efectivo depende del rol activo: auditar sin el rol deja
            // la pregunta "¿podía hacerlo?" sin respuesta reproducible.
            'active_role_id' => $context?->activeRole?->id,

            'action' => $action,
            'auditable_type' => $auditable === null ? null : $auditable::class,
            'auditable_id' => $auditable?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }
}
