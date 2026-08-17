<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de Spatie Laravel Permission, con teams = tenant (ARQUITECTURA_MAESTRA §4.2).
 *
 * Escritas a mano en lugar de publicar el stub del paquete, por tres razones que
 * el stub no puede cumplir:
 *
 *   1. `roles.tenant_id` es **NOT NULL** (D68). El stub lo crea nullable para
 *      permitir roles globales; aquí no existen, y el super admin vive fuera de
 *      Spatie para que la Regla A de ADR-002 no tenga excepciones.
 *   2. Llaves foráneas **reales** hacia `tenants`, que el stub no pone.
 *   3. Columnas añadidas: `permissions.module` y `.description` para agrupar el
 *      catálogo y ocultar los permisos de módulos inactivos; `roles.ulid`,
 *      `.is_system`, `.requires_two_factor` y `.description`.
 *
 * Los NOMBRES de tabla y columna respetan exactamente lo que Spatie espera; lo
 * que cambia son las restricciones y los añadidos.
 *
 * NOTA CRÍTICA (D9): activar teams no basta. Spatie suma los permisos de todos
 * los roles del usuario en el tenant, y Comandia opera bajo un único ROL ACTIVO.
 * La verificación efectiva pasa siempre por el servicio de contexto del kernel.
 * La única excepción, acotada y auditada, es la autorización por PIN (ADR-008).
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * `permissions` — EXCEPCIÓN DECLARADA a la Regla A: catálogo cerrado del
         * sistema, definido en un seeder versionado. El tenant combina permisos en
         * roles; no inventa permisos (D10). No contiene dato de ningún tenant.
         */
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();

            // `ascii_bin`: los nombres son identificadores en minúsculas con puntos
            // (`pos.accounts.charge`). Con la colación de la base, `POS.Accounts.Charge`
            // sería el mismo permiso, y un catálogo cerrado no puede permitirse eso.
            $table->string('name', 120)->charset('ascii')->collation('ascii_bin');
            $table->string('guard_name', 20)->charset('ascii')->collation('ascii_bin');

            // Agrupación por módulo (§4.2): la pantalla de armado de roles agrupa por
            // aquí, y los permisos de módulos inactivos no se muestran al tenant.
            $table->string('module', 40)->charset('ascii')->collation('ascii_bin');

            // El texto que ve el tenant al armar un rol. NOT NULL: un permiso sin
            // explicación es un permiso que alguien marcará sin entenderlo.
            $table->string('description', 160);

            $table->timestamps();

            $table->unique(['name', 'guard_name'], 'permissions_name_guard_unique');

            // La pantalla de armado de roles lee el catálogo agrupando por módulo.
            $table->index('module', 'permissions_module_index');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();

            // El "team" de Spatie ES el tenant, y por eso se llama `tenant_id`: así
            // las tablas del paquete cumplen la misma Regla A que el resto y el test
            // estructural las trata con el mismo criterio.
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->string('name', 80);
            $table->string('guard_name', 20)->charset('ascii')->collation('ascii_bin');

            // El rol Propietario no es borrable ni editable (D10).
            $table->boolean('is_system')->default(false);

            // 2FA obligable por tenant para roles administrativos (§10.2).
            $table->boolean('requires_two_factor')->default(false);

            $table->string('description', 160)->nullable();

            $table->timestamps();

            $table->unique('ulid', 'roles_ulid_unique');
            $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_name_guard_unique');

            // El stub de Spatie crea además un índice suelto sobre la llave de team.
            // Aquí se omite a propósito: es un PREFIJO del único de arriba, así que
            // MySQL ya lo puede usar. Un índice redundante sólo cuesta escrituras
            // (regla del proyecto: ningún índice sin justificación).
        });

        /**
         * `model_has_permissions` — DEBE PERMANECER VACÍA (D10).
         *
         * Existe porque Spatie la requiere. El tenant combina permisos en roles, no
         * asigna permisos directos: un permiso directo sería invisible para el
         * concepto de rol activo y rompería D9 en silencio. Un test estructural
         * verifica que esté vacía.
         */
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 120)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('tenant_id');

            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();

            $table->primary(
                ['tenant_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );

            // Búsqueda inversa "permisos de este modelo en este tenant". Empieza por
            // `tenant_id` y no puede resolverse con la PK, porque ahí `permission_id`
            // queda en medio.
            $table->index(['tenant_id', 'model_type', 'model_id'], 'model_has_permissions_tenant_model_index');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 120)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('tenant_id');

            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();

            $table->primary(
                ['tenant_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary'
            );

            // ESTA es la tabla que el diagrama de ARQUITECTURA_MAESTRA §4.1 llama
            // `membership_roles`: conceptualmente son los roles de la membresía, y
            // físicamente es (usuario, tenant, rol). Dejarlo escrito evita que alguien
            // cree una tabla `membership_roles` paralela.
            //
            // Índice de la consulta caliente: "roles de este usuario en este tenant",
            // que ocurre en cada resolución de contexto. No se puede servir con la PK
            // porque `role_id` está en medio.
            $table->index(['tenant_id', 'model_type', 'model_id'], 'model_has_roles_tenant_model_index');
        });

        /**
         * `role_has_permissions` — CUARTA Y ÚLTIMA excepción declarada a la Regla A.
         *
         * No lleva `tenant_id`, y hay que justificarlo bien porque §1 del diseño
         * hablaba de tres excepciones:
         *
         *   - El par (rol, permiso) está completamente determinado por `role_id`, y
         *     los roles SÍ están acotados por tenant. Una fuga por esta tabla exigiría
         *     antes filtrar un `role_id`, que es imposible sin romper el scope.
         *   - Spatie escribe aquí a través de su propia relación `sync()`, que sólo
         *     puebla `permission_id` y `role_id`. Una columna NOT NULL adicional
         *     rompería la asignación de permisos del paquete.
         *
         * Registrado como tal en el diseño de la Iteración 1.
         */
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');

            // Sin índice extra: la consulta real es "permisos de este rol", y la FK de
            // `role_id` ya crea el índice que la sirve.
        });

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
