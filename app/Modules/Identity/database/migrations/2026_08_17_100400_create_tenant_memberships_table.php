<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_memberships` — capa 2 de identidad y LA tabla del aislamiento de
 * personas: la pertenencia usuario–tenant vive aquí (ESPECIFICACIÓN_MAESTRA §4.1).
 *
 * NO guarda el nombre de la persona (D66): el nombre vive en `users` cuando hay
 * credenciales y en `employee_profiles` cuando no, con la precedencia del perfil
 * sobre el usuario. El invariante I1 —toda membresía sin credenciales tiene perfil
 * de empleado— lo impone el servicio de aplicación, porque la condición cruza dos
 * tablas y MySQL no admite un CHECK para eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // NULLABLE, y es una decisión de negocio y no una comodidad: §4.1 exige
            // que exista el empleado sin credenciales —el lavaloza que está en nómina
            // y jamás inicia sesión—. Consecuencia que hay que aceptar en todo el
            // proyecto: ninguna consulta puede asumir que la membresía tiene usuario,
            // y todo `join users` tiene que ser `left join`.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->char('employee_code', 20)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->enum('status', ['invited', 'active', 'suspended', 'terminated'])->default('invited');

            // Rol por defecto de la membresía (ARQUITECTURA_MAESTRA §4.1). El rol
            // ACTIVO no se persiste: es estado de sesión, y llega por el header
            // `X-Role` validado contra los roles asignados.
            //
            // `nullOnDelete`: borrar un rol no debe borrar a la persona. Se queda sin
            // rol por defecto, que es un estado corregible y visible.
            $table->foreignId('default_role_id')
                ->nullable()
                ->constrained('roles')
                ->nullOnDelete();

            // Sin esta bandera, dar de alta una sucursal nueva EXCLUIRÍA EN SILENCIO
            // al propietario y a los gerentes generales, y nadie se daría cuenta hasta
            // que alguien no encontrara la sucursal en el selector. Hace que "todas"
            // signifique todas, incluidas las futuras.
            $table->boolean('has_all_branches')->default(false);

            $table->foreignId('last_active_branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // El PIN vive en la membresía y no en el usuario: el PIN de un tenant no
            // es el PIN de otro (§4.1). Un mesero que trabaja en dos restaurantes
            // tiene dos PIN, y comprometer uno no compromete el otro.
            $table->string('pin_hash', 255)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamp('pin_set_at')->nullable();
            $table->unsignedTinyInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            $table->timestamps();

            $table->unique('ulid', 'tenant_memberships_ulid_unique');

            // Una persona tiene UNA membresía por tenant. MySQL permite múltiples NULL
            // en un índice único, así que los empleados sin credenciales conviven sin
            // estorbarse.
            $table->unique(['tenant_id', 'user_id'], 'tenant_memberships_tenant_user_unique');

            // El número de empleado es único dentro del tenant. Múltiples NULL
            // permitidos para quien no lo use.
            $table->unique(['tenant_id', 'employee_code'], 'tenant_memberships_tenant_code_unique');

            // Listado de personal filtrando por estado: toda pantalla de
            // administración de usuarios.
            $table->index(['tenant_id', 'status'], 'tenant_memberships_tenant_status_index');

            // La FK de `user_id` ya crea el índice que sirve al login ("¿a qué tenants
            // pertenece este correo?"). Es el único acceso del kernel que NO empieza
            // por `tenant_id`, y es legítimo: ocurre ANTES de que exista contexto de
            // tenant, en el flujo de identidad y no en código de dominio, así que no
            // viola la Regla B de ADR-002.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};
