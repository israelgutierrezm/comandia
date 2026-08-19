<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `preparation_areas.printer_id` y `terminals.printer_id` — el ruteo de la impresión.
 *
 * ## Los dos destinos, y por qué son distintos
 *
 * Una **comanda** va al área que la prepara: la de bebidas sale por la impresora de la barra aunque quien la capturó
 * esté en la caja. Un **ticket** —de cierre o final— sale por la impresora de la terminal donde se cobra, porque quien
 * lo necesita es el cliente que está ahí delante.
 *
 * O sea que el destino no lo decide quién imprime, sino **qué** se imprime. Ésa es la razón de las dos columnas.
 *
 * ## Nullable a propósito
 *
 * Un área sin impresora es un caso legítimo mientras nadie configure el hardware, y una fonda de una sola caja puede
 * operar sin imprimir comandas —el cocinero está a dos metros—. Obligar a asignar impresora convertiría un dato de
 * infraestructura opcional en un bloqueo para dar de alta un área.
 *
 * Lo que sí hará el POS es **decirlo**: al comandar a un área sin impresora, el trabajo no se crea y la respuesta
 * explica por qué en lugar de fallar en silencio. Un trabajo de impresión sin destino sería exactamente la clase de
 * fallo mudo que la Iteración 3 aprendió a no dejar pasar.
 *
 * ## `SET NULL` y no `RESTRICT`
 *
 * Dar de baja una impresora que se quemó es una operación normal, y no debe quedar bloqueada porque tres áreas la
 * citen. Con `SET NULL` esas áreas quedan sin destino —visible y corregible— en lugar de impedir la baja. Y una
 * impresora de verdad se **da de baja** por estado; el `SET NULL` sólo cubre el borrado real, que casi nunca ocurre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_areas', function (Blueprint $table): void {
            $table->foreignId('printer_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('printers')
                ->nullOnDelete();
        });

        Schema::table('terminals', function (Blueprint $table): void {
            $table->foreignId('printer_id')
                ->nullable()
                ->after('name')
                ->constrained('printers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('preparation_areas', function (Blueprint $table): void {
            $table->dropForeign(['printer_id']);
            $table->dropColumn('printer_id');
        });

        Schema::table('terminals', function (Blueprint $table): void {
            $table->dropForeign(['printer_id']);
            $table->dropColumn('printer_id');
        });
    }
};
