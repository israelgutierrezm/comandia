<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tema que eligió una persona vive en su MEMBRESÍA, no en el usuario.
 *
 * Un usuario puede pertenecer a varios negocios (§4.1), y su elección de apariencia es por negocio: el mismo correo
 * puede querer el tema oscuro en un restaurante y el institucional en otro. `NULL` significa «usa el tema por omisión
 * del negocio», que es la cascada que resuelve el servicio.
 *
 * `nullOnDelete` como los roles: retirar un tema no borra a la persona, sólo la deja sin elección —un estado corregible
 * y visible— y la deja caer en el default del negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->foreignId('theme_id')
                ->nullable()
                ->after('last_active_branch_id')
                ->constrained('themes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('theme_id');
        });
    }
};
