<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave foránea que el paso 4 dejó pendiente.
 *
 * `financial_movements.pos_session_id` nació como columna **sin constraint** porque `pos_sessions` no existía todavía: el
 * diario va antes que la sesión —sin diario no hay corte (D232)— y la sesión necesita métodos de pago para sus
 * declaraciones. Quedó anotado en la migración del diario y en el diseño de la iteración, y se cierra aquí.
 *
 * Una FK que falta es una fuga de integridad que nadie ve hasta que hay datos huérfanos: un asiento apuntando a una
 * sesión borrada saldría en el corte de una caja que no existe.
 *
 * ## Vive en `Pos` y no en `Finance`, y es deliberado
 *
 * La migración que puede crear la restricción es la que corre **después** de las dos tablas, y ésa es del módulo que
 * llega segundo. Ponerla en `Finance` obligaría a fecharla después de las migraciones de `Pos`, con lo que el orden de
 * los archivos dejaría de contar la historia: parecería que `Finance` se construyó después.
 *
 * `RESTRICT`: borrar una sesión con asientos dejaría el corte sin poder explicarse. Las sesiones no se borran — se
 * cierran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_movements', function (Blueprint $table): void {
            $table->foreign('pos_session_id', 'financial_movements_pos_session_id_foreign')
                ->references('id')
                ->on('pos_sessions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_movements', function (Blueprint $table): void {
            $table->dropForeign('financial_movements_pos_session_id_foreign');
        });
    }
};
