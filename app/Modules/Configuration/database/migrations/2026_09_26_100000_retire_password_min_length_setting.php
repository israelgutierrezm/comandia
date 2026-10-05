<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retira los valores guardados de `security.password_min_length` (diseño de acceso, D373).
 *
 * La llave salió del catálogo: nadie la leía y no podía gobernar una contraseña de plataforma. Un valor que un negocio
 * hubiera guardado quedaría huérfano en `tenant_settings`, sin definición que lo interprete; se borra. No hay `down`
 * que valga la pena: el valor no gobernaba nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_settings')->where('setting_key', 'security.password_min_length')->delete();
    }

    public function down(): void
    {
        // Sin vuelta: el valor nunca se aplicó a nada.
    }
};
