<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitaciones de acceso (diseño de acceso, fase 3).
 *
 * Reemplazan la contraseña que tecleaba el administrador: el negocio invita por correo y la persona crea su contraseña
 * —o acepta con la suya, si ya trabaja en otro negocio— desde un enlace que vence en 7 días y se usa una vez. La cuenta
 * global (`users`) no se crea ni se liga hasta que la persona acepta: nadie queda en un negocio sin haberlo querido.
 *
 * ## `membership_invitations`
 *
 * - `token_hash`: el token sólo existe en claro en el enlace; aquí, su SHA-256. `unique` porque aceptar busca por él, y
 *   lo hace SIN contexto de negocio (el enlace llega desde fuera): es la única búsqueda de la tabla que no empieza por
 *   `tenant_id`, y la justifica que el token es un secreto de 64 caracteres, como el de la app.
 * - `(tenant_id, membership_id)`: la invitación vigente de una persona, al reenviar (se cancela la anterior) y al pintar
 *   su ficha.
 * - Una sola vigente por membresía se garantiza en la aplicación, bajo lock de la membresía: MySQL no tiene índices
 *   parciales, y un `unique(membership_id)` impediría conservar las vencidas y canceladas, que son historia.
 * - El CHECK impide que una invitación esté aceptada y cancelada a la vez.
 * - `membership_id` en cascada: si la membresía desaparece, su invitación no tiene a quién dar acceso.
 *   `invited_by_membership_id` restrictiva, como toda firma de quien actuó.
 *
 * ## `membership_invitation_roles`
 *
 * Los roles que la persona recibe al aceptar. Existen aparte porque los roles viven en la CUENTA (Spatie con equipos =
 * negocio) y la persona invitada todavía no la tiene; y sin JSON en datos de dominio, la lista va en su tabla.
 * `unique(invitation_id, role_id)` es a la vez la regla y el índice de la búsqueda por invitación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_invitations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();

            $table->string('email', 150);
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('invited_by_membership_id')->constrained('tenant_memberships')->restrictOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique('ulid', 'membership_invitations_ulid_unique');
            $table->unique('token_hash', 'membership_invitations_token_unique');
            $table->index(['tenant_id', 'membership_id'], 'membership_invitations_tenant_membership_index');
        });

        DB::statement(
            'ALTER TABLE membership_invitations ADD CONSTRAINT membership_invitations_single_outcome '
            .'CHECK (accepted_at IS NULL OR revoked_at IS NULL)'
        );

        Schema::create('membership_invitation_roles', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained('membership_invitations')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->unique(['invitation_id', 'role_id'], 'membership_invitation_roles_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_invitation_roles');
        Schema::dropIfExists('membership_invitations');
    }
};
