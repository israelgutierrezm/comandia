<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `print_agents` — quién puede reclamar trabajos de impresión.
 *
 * ## Un agente NO es un usuario, y ésa es la decisión
 *
 * Vive en una computadora de la cocina o en una tableta con el puente de Flutter, y lo único que hace es preguntar «¿hay
 * algo pendiente en mi sucursal?», imprimirlo y reportar el resultado. No tiene rol activo, no tiene permisos y no opera
 * nada.
 *
 * Darle una membresía sería lo cómodo —ya existe todo el mecanismo— y sería abrirle la API entera a un proceso que corre
 * sin vigilancia en una máquina que cualquiera puede tocar. Un token robado de la cocina podría entonces consultar
 * ventas, cambiar precios o cancelar cuentas. Con un agente, un token robado sólo puede pedir e imprimir los trabajos de
 * **su** sucursal, que es exactamente el daño mínimo.
 *
 * ## `token_hash` y no el token
 *
 * Por lo mismo que un PIN o una contraseña: la base guarda el hash. Al alta se muestra el token una vez y no se puede
 * volver a ver — si se pierde, se rota. Guardarlo en claro convertiría un volcado de la base en acceso a todas las
 * impresoras de todos los negocios.
 *
 * ## `last_seen_at`, que es lo que contesta «¿por qué no imprime?»
 *
 * La pregunta más frecuente de una cocina no es «¿falló el trabajo?», es «¿está vivo el agente?». Sin esta columna, un
 * agente apagado y un agente sin trabajos se ven idénticos: cero movimiento. Con ella, la pantalla dice «visto hace 3
 * segundos» o «visto hace 2 horas», y eso decide si se revisa la impresora o la computadora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_agents', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Un agente es DE una sucursal: sólo ve los trabajos de ahí. Es lo que acota el daño de un token robado, y
            // por eso no es nullable ni admite «todas las sucursales» como sí lo admite una membresía.
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->string('name', 60);

            $table->string('token_hash', 255);

            $table->timestamp('last_seen_at')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'print_agents_ulid_unique');

            // Por el hash se entra al autenticar, y es la consulta de CADA petición del agente — que pregunta cada
            // pocos segundos. Sin este índice, el sondeo de cinco agentes recorrería la tabla completa todo el día.
            $table->unique('token_hash', 'print_agents_token_hash_unique');

            $table->index(['tenant_id', 'branch_id', 'status'], 'print_agents_tenant_branch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_agents');
    }
};
