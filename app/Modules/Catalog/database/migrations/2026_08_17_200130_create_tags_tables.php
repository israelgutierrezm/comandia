<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tags` y `article_tag` — etiquetas libres por tenant (D19).
 *
 * Las etiquetas **no** tienen `status`, a diferencia del resto del catálogo: una etiqueta que ya no
 * se usa se borra y el pivote cae con ella por CASCADE. No hay histórico que preservar —una etiqueta
 * no aparece en ningún documento— así que D80 ("sin soft deletes, ciclo de vida con status") no
 * aplica: aquí sí se borra de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('name', 60);

            $table->timestamps();

            $table->unique('ulid', 'tags_ulid_unique');

            // El nombre va con la colación de la base (acento- y caso-insensible, D58), que aquí es
            // lo correcto: "Sin gluten" y "sin gluten" son la misma etiqueta y tener las dos sería
            // un error de captura que el tenant no entendería.
            $table->unique(['tenant_id', 'name'], 'tags_tenant_name_unique');
        });

        Schema::create('article_tag', function (Blueprint $table): void {
            // Regla A sin excepciones de conveniencia: `tenant_id` va aunque sea alcanzable por
            // FK. ADR-002 lo dice con esas palabras ("aunque sea alcanzable por FKs").
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            $table->foreignId('tag_id')
                ->constrained('tags')
                ->cascadeOnDelete();

            // PK compuesta en lugar de `id`: el par ya identifica la fila, y una PK autoincrement
            // aquí sólo añadiría una columna y un índice que nadie consulta.
            $table->primary(['article_id', 'tag_id']);

            // La dirección inversa: "todos los artículos con esta etiqueta". La PK cubre la
            // directa; sin este índice, filtrar por etiqueta recorrería el pivote completo.
            $table->index(['tenant_id', 'tag_id'], 'article_tag_tenant_tag_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_tag');
        Schema::dropIfExists('tags');
    }
};
