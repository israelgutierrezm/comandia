<?php

declare(strict_types=1);

use App\Modules\Configuration\Application\ThemeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Re-tiñe el tema PREDETERMINADO al de la marca Comandia (cian/teal sobre azul marino #2F455C).
 *
 * El seeder ya cambió para los negocios nuevos; los que ya existían tienen sembrado el tema por omisión
 * con la paleta anterior (azul océano), así que hay que re-aplicarles el nombre y los tokens nuevos. Se
 * lee la MISMA definición del seeder (`ThemeSeeder::THEMES`) para no tener dos verdades, y se actualiza
 * SÓLO el tema por defecto por su `clave` estable —no se toca ningún otro tema ni las selecciones de los
 * usuarios—. Inserción directa (sin Eloquent) para no depender del contexto de tenant en la migración.
 *
 * En la base de pruebas no hay negocios al migrar, así que aquí no hace nada; cada test crea el suyo por
 * el alta, que ya siembra la paleta nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        /** @var array{clave: string, nombre: string, tokens: array<string, string>} $default */
        $default = collect(ThemeSeeder::THEMES)->firstWhere('es_default', true);

        foreach (DB::table('themes')->where('key', $default['clave'])->get() as $theme) {
            DB::table('themes')->where('id', $theme->id)->update([
                'name' => $default['nombre'],
                'updated_at' => $now,
            ]);

            // El conjunto de tokens no cambia (mismas 13 llaves), sólo sus valores: un UPDATE por llave basta.
            foreach ($default['tokens'] as $token => $valor) {
                DB::table('theme_tokens')
                    ->where('theme_id', $theme->id)
                    ->where('token', $token)
                    ->update(['value' => $valor, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // No se revierte: reponer la paleta anterior pisaría cualquier personalización y no aporta nada.
    }
};
