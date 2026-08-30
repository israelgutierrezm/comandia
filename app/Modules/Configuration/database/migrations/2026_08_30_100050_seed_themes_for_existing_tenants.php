<?php

declare(strict_types=1);

use App\Modules\Configuration\Application\ThemeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rellena el catálogo de temas para los negocios que YA existían.
 *
 * Los negocios nuevos los siembran por el evento de alta; los que ya estaban no vieron ese evento nunca, así que sin
 * esto se quedarían sin temas y el resolutor no tendría de dónde tomar la apariencia por omisión.
 *
 * Inserción directa —no Eloquent— para no depender del contexto de tenant dentro de la migración: el `tenant_id` se pone
 * a mano en cada fila. Lee la MISMA definición que el seeder (`ThemeSeeder::THEMES`) para no tener dos verdades. En la
 * base de pruebas no hay negocios al migrar, así que aquí no hace nada; cada test crea el suyo por el alta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            // Idempotente: un negocio que ya tiene temas (sembrados por el alta) no se toca.
            if (DB::table('themes')->where('tenant_id', $tenantId)->exists()) {
                continue;
            }

            foreach (ThemeSeeder::THEMES as $datos) {
                $themeId = DB::table('themes')->insertGetId([
                    'ulid' => Str::upper((string) Str::ulid()),
                    'tenant_id' => $tenantId,
                    'key' => $datos['clave'],
                    'name' => $datos['nombre'],
                    'is_default' => $datos['es_default'],
                    'allows_user_override' => $datos['permite_override'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $filas = [];

                foreach ($datos['tokens'] as $token => $valor) {
                    $filas[] = [
                        'tenant_id' => $tenantId,
                        'theme_id' => $themeId,
                        'token' => $token,
                        'value' => $valor,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('theme_tokens')->insert($filas);
            }
        }
    }

    public function down(): void
    {
        // Los temas cuelgan del tenant por FK en cascada; no se revierten aquí por no borrar elecciones de usuarios.
    }
};
