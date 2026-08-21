<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le faltaba al piso para poder DIBUJARSE (Iteración 5, §3).
 *
 * Cuatro columnas y ninguna tabla nueva. El modelo de datos del salón lo construyó la Iteración 4 —planos, zonas y
 * mesas con sus coordenadas lógicas— y lo dejó operable sin editor a propósito. Esto es lo que hace falta para que un
 * humano lo mueva con el ratón y para que dos personas no se pisen al hacerlo.
 *
 * ## El lienzo tiene tamaño, y la unidad lógica es el CENTÍMETRO
 *
 * ADR-003 dice «coordenadas lógicas, nunca píxeles» y no dice de qué. Un número sin unidad no se puede validar ni
 * dibujar a escala: hoy una mesa mide `80.00` y nadie sabe si es un escritorio o un salón. Con el centímetro fijado,
 * una mesa de cuatro es 80×80, un pasillo mide 90 y un comedor de 12×8 m son 1200×800 — que es la omisión.
 *
 * Sin `canvas_width`/`canvas_height` el `viewBox` del SVG sería una suposición del cliente, y dos clientes con
 * suposiciones distintas dibujarían el mismo plano diferente. Los datos ya sembrados quedan coherentes con esta
 * lectura sin migrar nada: mesas de 80×80 cm separadas 1.2 m, en un comedor pequeño.
 *
 * ## `version`: dos gerentes editando a la vez
 *
 * Arrastrar doce mesas y guardar produce doce escrituras. Si la quinta falla, el plano queda a medias —mitad nuevo,
 * mitad viejo— y nadie sabe cuál es cuál. Y dos personas editando se pisan **sin enterarse**: el resultado no es el
 * plano de ninguna de las dos.
 *
 * Es el mismo mecanismo que `pos_accounts.version` usa desde la Iteración 4 para que dos terminales no cobren la misma
 * cuenta. Un 409 con el plano actual es una respuesta útil; un plano mezclado no lo es.
 *
 * ## `archived_at` va APARTE del `status` que ya existe
 *
 * El enum de `status` dice *qué pasa ahora en el piso* —libre, ocupada, cuenta solicitada— y esto dice *si la mesa
 * existe siquiera*. Son ortogonales, y meterlos en la misma columna perdería una de las dos verdades: una mesa
 * retirada con una cuenta abierta encima tiene que seguir viéndose hasta que se cobre, y «archivada» competiría con
 * «ocupada».
 *
 * Borrarla no es opción: `pos_accounts.table_id` es `RESTRICT` y debe serlo, porque la cuenta de anoche dice en qué
 * mesa se sentó la gente y eso no se reescribe.
 *
 * Sin índice para `archived_at`: son decenas de filas por sucursal, no miles, y el índice compuesto que ya existe por
 * `(tenant_id, branch_id, status)` sigue resolviendo la consulta del piso. Ningún índice sin justificación escrita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('floor_plans', function (Blueprint $table): void {
            $table->decimal('canvas_width', 8, 2)->default('1200.00')->after('name');
            $table->decimal('canvas_height', 8, 2)->default('800.00')->after('canvas_width');

            // Empieza en 1 y no en 0: un plano recién creado ya tiene una versión que el editor puede mandar de vuelta.
            $table->unsignedInteger('version')->default(1)->after('is_default');
        });

        // Un lienzo de cero o negativo haría que toda coordenada quedara fuera de él, y el editor no tendría dónde
        // dibujar. Va en la base y no sólo en el Form Request porque el sembrador y las pruebas escriben directo.
        DB::statement(<<<'SQL'
            ALTER TABLE `floor_plans`
            ADD CONSTRAINT `chk_floor_plans_canvas_positive`
            CHECK (`canvas_width` > 0 AND `canvas_height` > 0)
        SQL);

        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('joined_to_table_id');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `floor_plans` DROP CONSTRAINT `chk_floor_plans_canvas_positive`');

        Schema::table('floor_plans', function (Blueprint $table): void {
            $table->dropColumn(['canvas_width', 'canvas_height', 'version']);
        });

        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
