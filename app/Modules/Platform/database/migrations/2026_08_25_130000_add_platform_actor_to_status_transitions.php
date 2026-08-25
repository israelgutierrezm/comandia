<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El actor de una transición de estado cuando la hace un super admin de la PLATAFORMA.
 *
 * Distinto de `actor_user_id` (personal de un negocio) y de ambos nulos (el sistema: un impago detectado por un job,
 * una purga programada). Con esto, «quién suspendió este negocio» queda atribuido cuando fue el operador del SaaS —una
 * acción sensible que no debe quedar sin dueño—. La tabla sigue siendo append-only: esto es esquema, no un UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_status_transitions', function (Blueprint $table): void {
            $table->foreignId('actor_platform_admin_id')
                ->nullable()
                ->after('actor_user_id')
                ->constrained('platform_admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_status_transitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_platform_admin_id');
        });
    }
};
