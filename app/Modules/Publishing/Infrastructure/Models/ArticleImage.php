<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Support\Facades\Storage;

/**
 * Una imagen de la galería de un artículo (Iteración 8, Tanda A). Se guarda en el disco `public`: una foto de producto es
 * contenido público (se muestra en el menú/tienda al cliente), no un archivo sensible.
 */
final class ArticleImage extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'article_images';

    protected $fillable = [
        'article_id',
        'disk_path',
        'alt_text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** La URL pública de la imagen, para pintarla en cualquier vitrina. */
    public function url(): string
    {
        return Storage::disk('public')->url($this->disk_path);
    }
}
