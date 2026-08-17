<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_entries` — bitácora técnica INMUTABLE (§6.7, D47).
 *
 * Junto con los payloads de trabajos de impresión, la ÚNICA tabla del proyecto con
 * columnas JSON permitidas.
 *
 * Sin `updated_at`: append-only. La inmutabilidad la impone el modelo lanzando
 * excepción en `update` y `delete`, y hay un test que lo verifica. Los triggers de
 * MySQL como defensa en profundidad se evalúan en la Iteración 11: hoy añadirían un
 * mecanismo fuera del alcance de las pruebas.
 *
 * NINGUNA tabla referencia `audit_entries` con una FK, y es deliberado: el
 * particionamiento por fecha previsto como evolución exige que la llave primaria
 * incluya la columna de partición, así que ese día habrá que pasar la PK a
 * `(id, created_at)`. Sin FKs entrantes, ese cambio es indoloro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            // Regla A. En una acción del super admin sobre un tenant, es el tenant
            // afectado.
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->foreignId('terminal_id')
                ->nullable()
                ->constrained('terminals')
                ->nullOnDelete();

            // NULL = acción del sistema (job, scheduler), no de una persona.
            //
            // `restrictOnDelete` y no `nullOnDelete`, a diferencia del resto: esta
            // columna es la EVIDENCIA DURADERA de quién actuó. Con `nullOnDelete`,
            // borrar un usuario borraría su rastro de toda la bitácora —una bitácora
            // inmutable que pierde a su actor no es inmutable en lo que importa—.
            // Con `restrict`, "no se puede borrar a alguien que tiene historia" pasa de
            // ser una convención a ser estructural.
            //
            // `users` es global al SaaS, así que no la arrastra el cascade de un tenant
            // y este restrict nunca interfiere con la purga de un tenant.
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // ---------------------------------------------------------------------
            // LAS DOS COLUMNAS DE ACTOR SON EL CORAZÓN DEL CONTROL ANTIFRAUDE.
            //
            // Cuando un mesero pide un descuento y el gerente teclea su PIN,
            // `actor_membership_id` es el mesero y `authorized_by_membership_id` es el
            // gerente. Una sola columna de actor haría imposible distinguir "el
            // gerente aplicó el descuento" de "el gerente autorizó que el mesero lo
            // aplicara", y esa distinción es exactamente lo que necesita el reporte de
            // robo hormiga (§6.3, §9).
            // ---------------------------------------------------------------------
            $table->foreignId('actor_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->nullOnDelete();

            $table->foreignId('authorized_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->nullOnDelete();

            // Con D9 el permiso efectivo depende del rol activo: auditar la acción sin
            // el rol deja la pregunta "¿podía hacerlo?" sin respuesta reproducible.
            $table->foreignId('active_role_id')
                ->nullable()
                ->constrained('roles')
                ->nullOnDelete();

            // Catálogo cerrado en código, por el mismo motivo que los permisos: un
            // `action` escrito a mano produce un evento que ningún reporte encuentra.
            $table->string('action', 80)->charset('ascii')->collation('ascii_bin');

            $table->string('auditable_type', 120)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip_address', 45)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('user_agent', 255)->nullable();

            // Precisión de milisegundos: dos acciones del mismo segundo tienen que
            // poder ordenarse en una investigación.
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique('ulid', 'audit_entries_ulid_unique');

            // -----------------------------------------------------------------
            // CUATRO índices de consulta, elegidos uno por uno. Un quinto
            // necesitaría su propia justificación escrita.
            //
            // PERO el conteo real de índices de esta tabla es mayor, y hay que
            // decirlo: InnoDB EXIGE un índice por cada llave foránea y lo crea
            // solo si no existe. Con seis FKs nullable, la tabla acaba con seis
            // índices de una columna que nadie pidió, más los cuatro de abajo.
            //
            // Verificado en el esquema real: el FK de `tenant_id` NO genera índice
            // extra porque InnoDB reutiliza el índice cuya columna más a la
            // izquierda coincide, y `audit_entries_tenant_created_index` empieza
            // por `tenant_id`. Los otros seis no tienen esa suerte: sus columnas
            // nunca van primero, porque ADR-002 manda que los índices compuestos
            // empiecen por `tenant_id`.
            //
            // Se acepta el costo. El proyecto exige constraints reales
            // (definition of done, punto 2) y la integridad referencial de la
            // bitácora vale más que las escrituras que ahorraríamos: además la
            // escritura de auditoría es ASÍNCRONA (cola `default`), así que el
            // costo lo paga un worker y no el usuario esperando su ticket.
            //
            // Si alguna vez el volumen lo exige, la salida NO es quitar las FKs:
            // es el particionamiento por fecha ya previsto, que reduce el tamaño
            // de cada índice sin renunciar a la integridad.
            // -----------------------------------------------------------------

            // La vista principal: "últimas acciones de este tenant".
            $table->index(['tenant_id', 'created_at'], 'audit_entries_tenant_created_index');

            // "Historia completa de esta entidad": el caso de uso del auditor.
            $table->index(
                ['tenant_id', 'auditable_type', 'auditable_id', 'created_at'],
                'audit_entries_tenant_auditable_index'
            );

            // "Qué hizo esta persona": investigación de un empleado.
            $table->index(
                ['tenant_id', 'actor_membership_id', 'created_at'],
                'audit_entries_tenant_actor_index'
            );

            // El reporte dedicado de descuentos, cortesías y cancelaciones que §9 exige
            // como mitigación del robo hormiga.
            $table->index(['tenant_id', 'action', 'created_at'], 'audit_entries_tenant_action_index');

            // `authorized_by_membership_id` NO se indexa: la investigación de
            // autorizaciones se hace por `action` en un rango de fechas, que ya está
            // cubierto por el índice anterior.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
