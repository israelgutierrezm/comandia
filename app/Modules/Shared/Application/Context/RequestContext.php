<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Context;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use RuntimeException;

/**
 * Contexto completo del request, inmutable (ARQUITECTURA_MAESTRA §3).
 *
 *     {tenant, usuario, membresía, rol activo, sucursal activa, terminal}
 *
 * Lo construye el middleware de resolución una vez por petición y se inyecta donde
 * haga falta. `readonly` de PHP garantiza que nadie lo mute a media petición: si el
 * rol activo pudiera cambiar en medio de un caso de uso, la verificación de permisos
 * del principio y la del final podrían discrepar.
 *
 * Las superficies públicas —menú QR, tienda— usan {@see self::forPublic()}: tienen
 * tenant pero no usuario, y sólo lectura.
 */
final readonly class RequestContext
{
    private function __construct(
        public Tenant $tenant,
        public ?User $user,
        public ?TenantMembership $membership,
        public ?Role $activeRole,
        public ?Branch $activeBranch,
        public ?Terminal $terminal,
        public bool $isReadOnly,
        public bool $isPublic,
    ) {}

    public static function forMember(
        Tenant $tenant,
        User $user,
        TenantMembership $membership,
        ?Role $activeRole = null,
        ?Branch $activeBranch = null,
        ?Terminal $terminal = null,
    ): self {
        return new self(
            tenant: $tenant,
            user: $user,
            membership: $membership,
            activeRole: $activeRole,
            activeBranch: $activeBranch,
            terminal: $terminal,
            // Derivado del estado del tenant, no recibido: `read_only` es una decisión
            // comercial y no algo que un llamador pueda relajar.
            isReadOnly: ! $tenant->allowsWrites(),
            isPublic: false,
        );
    }

    /**
     * Superficies públicas sin autenticación (Iteración 9).
     */
    public static function forPublic(Tenant $tenant): self
    {
        return new self(
            tenant: $tenant,
            user: null,
            membership: null,
            activeRole: null,
            activeBranch: null,
            terminal: null,
            isReadOnly: true,
            isPublic: true,
        );
    }

    /**
     * Copia con otra sucursal activa. Cambiar de sucursal es una operación de sesión
     * auditada, y produce un contexto nuevo en lugar de mutar el existente.
     */
    public function withBranch(?Branch $branch): self
    {
        return new self(
            $this->tenant,
            $this->user,
            $this->membership,
            $this->activeRole,
            $branch,
            $this->terminal,
            $this->isReadOnly,
            $this->isPublic,
        );
    }

    public function withRole(?Role $role): self
    {
        return new self(
            $this->tenant,
            $this->user,
            $this->membership,
            $role,
            $this->activeBranch,
            $this->terminal,
            $this->isReadOnly,
            $this->isPublic,
        );
    }

    // -----------------------------------------------------------------
    // Acceso exigente
    // -----------------------------------------------------------------

    /**
     * La membresía, exigiendo que exista.
     *
     * Los accesores "requeridos" existen para que el código de dominio no llene de
     * comprobaciones nulas lo que en su camino de ejecución siempre está presente. Un
     * caso de uso autenticado tiene membresía; si no la tiene, es un error de ruteo
     * y hay que verlo.
     */
    public function requireMembership(): TenantMembership
    {
        return $this->membership ?? throw new RuntimeException(
            'El contexto no tiene membresía. ¿Se está ejecutando un caso de uso autenticado '
            .'en una superficie pública o sin el middleware de contexto?'
        );
    }

    public function requireActiveRole(): Role
    {
        return $this->activeRole ?? throw new RuntimeException(
            'El contexto no tiene rol activo. La verificación de permisos evalúa el rol activo '
            .'(D9) y sin él no hay nada que evaluar.'
        );
    }

    public function requireActiveBranch(): Branch
    {
        return $this->activeBranch ?? throw new RuntimeException(
            'El contexto no tiene sucursal activa. Toda operación de sucursal exige que el '
            .'cliente la haya seleccionado (header X-Branch) o que la membresía tenga una sola.'
        );
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null && $this->membership !== null;
    }
}
