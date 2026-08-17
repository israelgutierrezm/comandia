<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `personal_access_tokens` + contexto de tenant (D69).
 *
 * La app Flutter y los agentes de impresión se autentican por token, sin sesión.
 * El `tenant_id` no puede venir de la petición (ADR-002), así que **viaja con la
 * credencial**: es una llave foránea verificable y no una cadena en `abilities`.
 *
 * Consecuencias, todas deseables:
 *   - Un token no puede cruzar tenants ni por error.
 *   - Un usuario que trabaja en dos restaurantes necesita dos tokens. Correcto:
 *     son dos credenciales para dos contextos, igual que tiene dos PIN.
 *   - Dar de baja a alguien de un tenant es borrar sus tokens de ese tenant, y el
 *     `cascadeOnDelete` sobre la membresía lo hace solo.
 *
 * El rol activo y la sucursal activa NO van aquí: siguen viajando en los headers
 * `X-Role` y `X-Branch` validados contra el alcance de la membresía. La diferencia
 * es deliberada — el tenant es propiedad de la credencial y no se negocia; el rol
 * y la sucursal son elecciones legítimas del operador entre lo ya concedido.
 *
 * Ambas columnas son NOT NULL: no existe token de Comandia sin tenant. Se declaran
 * como tal desde el principio porque la tabla está vacía; hacerlo después exigiría
 * decidir qué hacer con los tokens huérfanos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('tenant_id')
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('membership_id')
                ->after('tenant_id')
                ->constrained('tenant_memberships')
                ->cascadeOnDelete();

            // "Revocar todos los tokens de esta persona en este tenant": la operación
            // de baja de personal. Las FKs crean índices sueltos por columna, pero
            // ninguno sirve esta consulta compuesta empezando por `tenant_id`.
            $table->index(['tenant_id', 'membership_id'], 'personal_access_tokens_tenant_membership_index');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('personal_access_tokens_tenant_membership_index');
            $table->dropConstrainedForeignId('membership_id');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
