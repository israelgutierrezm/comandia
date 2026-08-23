<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de notificaciones internas (Iteración 7, Tanda D2, §6.9).
 *
 * Un aviso por usuario o por rol: «tu exportación está lista», «stock bajo», «diferencia de corte»… Se consume desde
 * eventos de dominio ya catalogados; el primer productor real es «export listo» (Tanda B).
 *
 * Un aviso va a una MEMBRESÍA concreta o a un ROL (difusión). Se lee si `recipient_membership_id` es mío o
 * `recipient_role_id` es mi rol activo. Columnas tipadas, sin JSON: `type` clasifica, `url` enlaza a la pantalla
 * relevante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->foreignId('recipient_membership_id')->nullable()->constrained('tenant_memberships')->cascadeOnDelete();
            $table->foreignId('recipient_role_id')->nullable()->constrained('roles')->cascadeOnDelete();

            $table->string('type', 60);
            $table->string('title', 160);
            $table->string('body', 400)->nullable();
            $table->string('url', 255)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'notifications_ulid_unique');

            // «Mis avisos no leídos, los más recientes»: la consulta de la campana, por membresía y por rol.
            $table->index(['tenant_id', 'recipient_membership_id', 'read_at'], 'notifications_membership_index');
            $table->index(['tenant_id', 'recipient_role_id', 'read_at'], 'notifications_role_index');
        });

        // Un aviso sin destinatario no le llega a nadie: exige membresía o rol.
        DB::statement(
            'ALTER TABLE `notifications` ADD CONSTRAINT `notifications_recipient_chk` '
            .'CHECK (`recipient_membership_id` IS NOT NULL OR `recipient_role_id` IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
