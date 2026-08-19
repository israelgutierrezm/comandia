<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_memberships.last_active_role_id` — el rol activo se recuerda (D234).
 *
 * ## El defecto que cierra
 *
 * El rol activo viajaba por la cabecera `X-Role` en **una sola visita**, mientras la sucursal sí se recordaba en
 * `last_active_branch_id`. Así que el selector de la interfaz presentaba como **estado** algo que se deshacía en la
 * navegación siguiente, sin avisar (D228).
 *
 * Lo encontré verificando el conteo ciego de la Iteración 3: cambié a Almacenista, la hoja se mostró correctamente
 * ciega, navegué al listado y la columna de diferencias había vuelto. No era la pantalla — era el rol que había vuelto
 * a Propietario.
 *
 * Y la cara peligrosa: alguien que baja **deliberadamente** a un rol menor —para operar con menos privilegios, o para
 * revisar lo que ve su equipo— creía estar operando con ese rol y operaba con el mayor.
 *
 * ## Por qué `SET NULL` y no `RESTRICT`
 *
 * Borrar un rol es una operación legítima de la administración del negocio, y no debe quedar bloqueada porque alguien
 * lo tuviera activo la semana pasada. Con `SET NULL`, la membresía cae a su rol por omisión, que es exactamente el
 * comportamiento correcto: perder la preferencia no es perder el acceso.
 *
 * `RESTRICT` habría sido lo contrario — un rol que no se puede borrar por una preferencia de navegación.
 *
 * ## Sin índice
 *
 * No lo lleva a propósito, y CLAUDE.md exige justificar por escrito cada índice: esta columna **nunca se filtra ni se
 * agrupa**. Se lee siempre por la membresía que ya se resolvió por su llave primaria, así que un índice sólo costaría
 * escrituras. La FK crea el suyo por su cuenta en MySQL, que es más de lo que hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->foreignId('last_active_role_id')
                ->nullable()
                ->after('last_active_branch_id')
                ->constrained('roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropForeign(['last_active_role_id']);
            $table->dropColumn('last_active_role_id');
        });
    }
};
