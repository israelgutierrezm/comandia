<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Publishing\Infrastructure\Models\ArticleImage;
use App\Modules\Publishing\Infrastructure\Models\ArticlePublication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Administra la capa de publicación de un artículo (Iteración 8, Tanda A): descripción larga, orden, visibilidad y galería.
 *
 * `Publishing` depende de `Catalog` (recibe el artículo del Core para enriquecerlo); `Catalog` no conoce esta capa. Las
 * imágenes van al disco `public`: son contenido público (se muestran al cliente), y su ruta lleva el ULID del artículo para
 * que no se puedan enumerar.
 */
final class ManageArticlePublication
{
    public function publicationFor(Article $article): ArticlePublication
    {
        // Los valores por omisión se pasan explícitos —no se dejan a la columna— para que el modelo recién creado los
        // refleje en memoria: `firstOrCreate` no relee el default de la base, así que sin esto `is_visible` volvería null.
        return ArticlePublication::query()->firstOrCreate(
            ['article_id' => $article->id],
            ['is_visible' => true, 'sort_order' => 0],
        );
    }

    /**
     * @param  array{long_description?: string|null, sort_order?: int, is_visible?: bool}  $data
     */
    public function save(Article $article, array $data): ArticlePublication
    {
        $publication = $this->publicationFor($article);
        $publication->fill($data);
        $publication->save();

        return $publication;
    }

    public function addImage(Article $article, UploadedFile $file, ?string $altText): ArticleImage
    {
        // Ruta por ULID del artículo: pública pero no enumerable.
        $path = $file->store("publications/{$article->ulid}", 'public');

        $next = (int) ArticleImage::query()->where('article_id', $article->id)->max('sort_order');

        // `->refresh()` tras create: Eloquent devuelve el atributo ASIGNADO, no el almacenado; releer garantiza que el
        // Resource publique lo que quedó en la base (candado CreatedModelsAreRefreshed).
        return ArticleImage::create([
            'article_id' => $article->id,
            'disk_path' => $path,
            'alt_text' => $altText,
            'sort_order' => $next + 1,
        ])->refresh();
    }

    public function removeImage(ArticleImage $image): void
    {
        // Se borra el archivo además de la fila: una galería que borra la fila pero deja el archivo llena el disco de
        // huérfanos que nadie vuelve a referenciar.
        Storage::disk('public')->delete($image->disk_path);

        $image->delete();
    }
}
