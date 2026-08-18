<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `article_branch_overrides` — precio y disponibilidad por sucursal (§6.1).
 *
 * ## Una tabla con dos dimensiones y `NULL` = heredar
 *
 * Mismo patrón que la cascada de configuración del kernel. La ventaja concreta frente a dos tablas es que
 * `branch_id` es NOT NULL, así que el índice único funciona sin trucos y **no reaparece el problema de `NULL`
 * en índice único** que resolvió D78 y que en categorías obligó a una columna generada (D93).
 *
 * La cascada tiene **dos niveles y nada más**: override de sucursal → dato maestro del artículo. Dos niveles
 * se explican en una frase y se prueban en cuatro casos.
 *
 * ## El CANAL no está aquí, y es deuda declarada
 *
 * §6.1 pide "override por sucursal **y por canal**". En v1 sólo existe un canal transaccional —el POS—,
 * porque e-commerce es la Iteración 9 y es un módulo activable. Construir ahora la dimensión de canal
 * significaría diseñar y probar una cascada de cuatro niveles contra un solo canal, es decir, sin poder
 * verificar que sirve. Cuando llegue, se agrega una columna `channel` y se amplía el índice único: una
 * migración aditiva con datos reales para probarla.
 *
 * ## Una fila con todo en NULL no existe
 *
 * El servicio la borra: una fila que hereda todo es indistinguible de no tener override, y conservarla dejaría
 * la pregunta "¿esta sucursal tiene precio propio?" con dos respuestas posibles para el mismo estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_branch_overrides', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            // CASCADE: un override es configuración, no evidencia. Si la sucursal desapareciera, su precio
            // propio no significa nada — y el HISTORIAL del cambio sí se conserva, porque
            // `price_changes.branch_id` es RESTRICT.
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // NULL = hereda `articles.base_price`. Con IVA incluido, como el maestro (D30).
            $table->decimal('price', 12, 2)->nullable();

            // NULL = hereda `articles.is_available_in_pos`. Es lo que permite apagar un platillo en una
            // sucursal sin tocar el catálogo del negocio: la cocina de esa sucursal no lo prepara.
            $table->boolean('is_available_in_pos')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'article_id', 'branch_id'],
                'article_branch_overrides_unique'
            );

            // "El catálogo con overrides de esta sucursal", en una sola pasada. Es la consulta del POS al
            // pintar su pantalla: sin este índice, resolver el precio efectivo de 400 artículos recorrería
            // la tabla completa de overrides del negocio.
            $table->index(['tenant_id', 'branch_id'], 'article_branch_overrides_tenant_branch_index');
        });

        // Un precio negativo no es un descuento: es un artículo que paga al cliente por llevárselo (§6.3).
        DB::statement(<<<'SQL'
            ALTER TABLE `article_branch_overrides`
            ADD CONSTRAINT `chk_article_branch_overrides_price` CHECK (
                `price` IS NULL OR `price` >= 0
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_branch_overrides');
    }
};
