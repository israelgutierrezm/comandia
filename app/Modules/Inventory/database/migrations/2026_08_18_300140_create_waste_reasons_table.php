<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `waste_reasons` — catálogo de motivos de merma, **por tenant** (D27, §6.2).
 *
 * ## Por qué es un catálogo del tenant y no un enum del sistema
 *
 * Los motivos por los que se pierde mercancía dependen del negocio: una taquería tiene «se cayó al piso» y una
 * cafetería «se pasó de tueste». Un enum fijo obligaría a que todos usaran «Otro», y una merma con motivo «Otro»
 * no sirve para nada — el reporte de mermas del mes diría que el 90 % de las pérdidas son inexplicables.
 *
 * Es la misma razón por la que las etiquetas del catálogo son libres (D19) y los permisos NO lo son (D10): el
 * vocabulario del negocio lo pone el negocio; las reglas del sistema las pone el sistema.
 *
 * ## `requires_evidence` se guarda y todavía no se usa
 *
 * §6.2 pide evidencia fotográfica **opcional**, y P5 la difirió con su razón escrita: no existe almacenamiento de
 * archivos en el proyecto y §10 lo pone en la Iteración 11.
 *
 * La bandera sí se crea, a diferencia de la columna `evidence_path` que P5 recomendó NO crear. La diferencia: esto
 * es una **política** que el negocio configura hoy —«la merma por robo siempre lleva foto»— y que la UI puede
 * mostrar como advertencia antes de que exista la subida. Una columna vacía sería una promesa a medias; una
 * política declarada es una decisión tomada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_reasons', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('name', 80);

            // Política del negocio: este motivo exige foto. Se declara aunque la subida llegue después (P5).
            $table->boolean('requires_evidence')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'waste_reasons_ulid_unique');

            // Único por negocio: dos motivos con el mismo nombre volverían ambiguo cualquier reporte agrupado
            // por motivo, que es la única razón por la que el catálogo existe.
            $table->unique(['tenant_id', 'name'], 'waste_reasons_tenant_name_unique');

            // La consulta de la pantalla de captura: los motivos activos de este negocio.
            $table->index(['tenant_id', 'status'], 'waste_reasons_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_reasons');
    }
};
