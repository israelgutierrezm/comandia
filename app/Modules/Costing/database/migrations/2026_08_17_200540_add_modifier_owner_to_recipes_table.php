<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paga la deuda declarada en **D100**: el dueño de una receta pasa a ser artículo **XOR** modificador.
 *
 * El diseño (§3.1) definía así la propiedad desde el principio, pero `modifiers` no existía cuando se creó
 * `recipes` —era el paso 10 de la iteración— y una FK no se puede declarar contra una tabla ausente. Se
 * construyó con `article_id NOT NULL` y la deuda quedó escrita, con el plan exacto que esta migración ejecuta.
 *
 * ## Por qué existe una receta de modificador
 *
 * §6.1: "modificador con precio adicional **e impacto en receta por unidad**". "Extra queso" consume 30 g de
 * queso, y sin eso el costo del platillo con extras sería el mismo que sin ellos — el margen del extra saldría
 * del 100 %.
 *
 * ## El índice único sigue funcionando, y por eso no hace falta tocarlo
 *
 * `recipes_tenant_article_unique` era `(tenant_id, article_id)` y sigue imponiendo el invariante I1 —a lo más
 * una receta por artículo— porque **MySQL no deduplica NULL** en índices únicos: las recetas de modificador,
 * con `article_id` NULL, no colisionan entre sí. Es exactamente lo que D100 previó.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La FK se suelta antes de cambiar la nulabilidad: MySQL no admite alterar una columna con restricción
        // referencial viva sin ambigüedad de orden, y hacerlo por pasos explícitos deja claro qué se toca.
        Schema::table('recipes', function ($table): void {
            $table->dropForeign(['article_id']);
        });

        DB::statement('ALTER TABLE `recipes` MODIFY `article_id` BIGINT UNSIGNED NULL');

        Schema::table('recipes', function ($table): void {
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();

            // CASCADE igual que el artículo: la receta no tiene sentido sin su dueño.
            $table->foreignId('modifier_id')
                ->nullable()
                ->after('article_id')
                ->constrained('modifiers')
                ->cascadeOnDelete();

            // A lo más una receta por modificador. Funciona por lo mismo que la de artículo: las recetas de
            // artículo llevan `modifier_id` NULL y no colisionan entre sí.
            $table->unique(['tenant_id', 'modifier_id'], 'recipes_tenant_modifier_unique');
        });

        // El CHECK de exclusividad que D100 dejó pendiente: exactamente uno de los dos dueños.
        //
        // Es la alternativa a una relación polimórfica, y la razón es la integridad referencial: con
        // `owner_type`/`owner_id` nada impediría una receta huérfana apuntando a un id borrado, y el día que
        // apareciera esa fila el costeo devolvería un número sin explicación.
        DB::statement(<<<'SQL'
            ALTER TABLE `recipes`
            ADD CONSTRAINT `chk_recipes_single_owner` CHECK (
                (`article_id` IS NOT NULL AND `modifier_id` IS NULL)
                OR (`article_id` IS NULL AND `modifier_id` IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `recipes` DROP CONSTRAINT `chk_recipes_single_owner`');

        Schema::table('recipes', function ($table): void {
            $table->dropUnique('recipes_tenant_modifier_unique');
            $table->dropConstrainedForeignId('modifier_id');
        });

        // Volver a NOT NULL sólo es posible si no quedan recetas de modificador, que es coherente: la bajada
        // de esta migración presupone deshacer también las filas que introdujo.
        Schema::table('recipes', function ($table): void {
            $table->dropForeign(['article_id']);
        });

        DB::statement('ALTER TABLE `recipes` MODIFY `article_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('recipes', function ($table): void {
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
        });
    }
};
