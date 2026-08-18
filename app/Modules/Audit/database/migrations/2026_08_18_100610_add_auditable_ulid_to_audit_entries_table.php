<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `auditable_ulid` — el identificador PÚBLICO de la entidad auditada (aprobado tras la Iteración 2).
 *
 * ## Por qué hacía falta
 *
 * La bitácora es **evidencia**, y una evidencia tiene que poder leerse sola. Hasta ahora el asiento
 * guardaba la llave interna —`auditable_id`, un BIGINT— y esa llave sólo significa algo mientras la fila
 * original exista: el día que se borre, el asiento apunta a la nada y no queda forma de decir de qué
 * hablaba. Y la llave interna **no se puede exponer** por la API (D91, §7: nunca IDs secuenciales), así que
 * un asiento leído desde la API tampoco identificaba a la entidad.
 *
 * Resolver el ULID al leer sería una consulta por fila —una por asiento— sobre la tabla de mayor volumen
 * del sistema, y con un `LEFT JOIN` distinto por cada tipo auditable. La columna lo hace innecesario.
 *
 * ## Las filas anteriores se quedan en NULL, y es a propósito
 *
 * Se podría derivar el ULID de las filas existentes y rellenarlas. **No se hace**: `audit_entries` es
 * append-only por §7, y rellenar la columna sería un `UPDATE` masivo sobre la tabla de evidencia del
 * sistema. Que el valor sea derivado no cambia la naturaleza de la operación — quien audite la base
 * después vería filas modificadas con fecha posterior a su creación, que es exactamente la señal que la
 * inmutabilidad existe para descartar.
 *
 * Los asientos anteriores conservan su `auditable_type` y su llave interna, así que siguen siendo
 * rastreables desde la base de datos. Lo que no tienen es identificador público, y eso queda registrado.
 * Si alguna vez se quiere rellenar, es una decisión propia y no un efecto colateral de esta migración.
 *
 * ## Sobre agregar una columna a una tabla inmutable
 *
 * Inmutable se refiere a las **filas**, no al esquema: §7 prohíbe `UPDATE` y `DELETE` de registros, no la
 * evolución de la tabla. Aun así es un cambio del diseño del kernel y por eso exigió aprobación explícita
 * antes de escribirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            // `ascii_bin` como todos los ULID del proyecto (D58): son Crockford base32 y la comparación
            // tiene que ser exacta, no acento-insensible.
            $table->char('auditable_ulid', 26)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->nullable()
                ->after('auditable_id');

            // Índice compuesto que empieza por `tenant_id`, como toda tabla transaccional (§7).
            //
            // Justificación: es la consulta «todo lo que le pasó a ESTA entidad», que es la que se hace al
            // investigar un caso concreto —«¿quién le cambió el precio a las enchiladas?»— y la única
            // razón por la que se agrega la columna. Sin índice sería un recorrido de la tabla completa,
            // que es la más grande del sistema.
            //
            // `created_at` al final para que el orden descendente salga del índice: un caso se lee siempre
            // del último movimiento hacia atrás.
            $table->index(
                ['tenant_id', 'auditable_ulid', 'created_at'],
                'audit_entries_tenant_auditable_ulid_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropIndex('audit_entries_tenant_auditable_ulid_index');
            $table->dropColumn('auditable_ulid');
        });
    }
};
