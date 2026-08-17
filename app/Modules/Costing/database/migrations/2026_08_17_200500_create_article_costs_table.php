<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `article_costs` — historial INMUTABLE de costos (D14).
 *
 * Una de las tablas append-only que declara ARQUITECTURA_MAESTRA §7: sin UPDATE, sin DELETE,
 * corrección por registro nuevo. "Toda variación se historiza (costo, fecha, origen, actor)" es
 * literalmente D14, y el costo vigente es la última fila.
 *
 * ## `unit_cost` es DECIMAL(12,4) y §7 dice que el dinero va en (12,2) — P3, aprobada
 *
 * Un costo unitario **no es un monto**: es un monto **por unidad**. El gramo de sal cuesta
 * $0.000012; a dos decimales es cero, y toda receta que use sal costaría cero. La desviación está
 * acotada a costos unitarios y precios sugeridos —los montos siguen en (12,2)— y quedó escrita en
 * §7 de ARQUITECTURA_MAESTRA para que nadie la "corrija" de buena fe.
 *
 * ## `origin` distingue el costo de adquisición del costo calculado
 *
 * D14 dice "costo vigente = último costo **de adquisición**", y un platillo no se adquiere: su costo
 * se calcula desde su receta. Los dos viven aquí distinguidos por `origin`, porque el usuario quiere
 * UNA pantalla de "cómo evolucionó el costo de mis enchiladas" y partirla en dos tablas obligaría a
 * unirlas en cada lectura para siempre.
 *
 * Consecuencia que hay que respetar: el **promedio del periodo** de D14 se calcula sólo sobre los
 * orígenes de adquisición. Promediar costos calculados con costos de compra mezcla dos cosas
 * distintas y el número resultante no significa nada.
 *
 * `recipe_cascade` existe en el enum pero **nada lo escribe todavía**: el motor de costeo es el paso
 * 6 de la iteración y P5 —si los costos calculados van en esta tabla— sigue abierta. Se declara
 * ahora para que el enum no cambie después; si P5 se resuelve en contra, el valor queda sin uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_costs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT, igual que `audit_entries.actor_user_id`: un historial de costos es
            // evidencia, y borrar el artículo no puede borrar la historia de lo que costó. Por eso
            // los artículos se ARCHIVAN (D80).
            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            $table->decimal('unit_cost', 12, 4)->unsigned();

            $table->enum('origin', ['initial', 'manual', 'purchase', 'recipe_cascade']);

            // La cadena causal: "el costo de la torta cambió porque cambió el costo del jitomate",
            // con enlace. Es lo que convierte el historial en algo investigable en lugar de una
            // lista de números con fechas.
            $table->foreignId('source_cost_id')
                ->nullable()
                ->constrained('article_costs')
                ->restrictOnDelete();

            // Idempotencia de jobs (CLAUDE.md) hecha columna. El recálculo en cascada es un job, y
            // re-despacharlo NO puede duplicar historial. La llave la construye el job de forma
            // determinista y el índice único la hace imposible de violar aunque el código se
            // equivoque. Nullable: las capturas manuales no la necesitan.
            $table->string('idempotency_key', 100)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->string('notes', 200)->nullable();

            // NULL = lo calculó un job y no una persona. No se inventa un actor: un actor falso en
            // un registro de evidencia es indistinguible de uno real.
            $table->foreignId('actor_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Cuándo EMPEZÓ A VALER, que puede no ser cuándo se capturó: una factura de la semana
            // pasada se registra hoy con la fecha de la factura.
            $table->timestamp('effective_at');

            // Tabla inmutable: sólo `created_at`, sin `updated_at`. `useCurrent()` queda como red
            // para seeders e importaciones; el trait `Immutable` lo escribe desde PHP porque el
            // reloj de MySQL puede no estar en UTC (D85, descubierto en la Iteración 1).
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'article_costs_ulid_unique');

            // Las dos consultas de la tabla: el costo vigente (última fila) y el historial del
            // artículo. Y el promedio del periodo de D14, que es un AVG sobre este mismo rango.
            $table->index(
                ['tenant_id', 'article_id', 'effective_at'],
                'article_costs_tenant_article_effective_index'
            );

            $table->unique(['tenant_id', 'idempotency_key'], 'article_costs_idempotency_unique');
        });

        // Un costo de 0 es legítimo (una cortesía, un insumo regalado por el proveedor); uno
        // negativo no significa nada y propagaría un costo negativo a cada receta que lo use.
        DB::statement(<<<'SQL'
            ALTER TABLE `article_costs`
            ADD CONSTRAINT `chk_article_costs_not_negative` CHECK (`unit_cost` >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_costs');
    }
};
