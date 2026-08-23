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

    /**
     * La URL pública de la imagen, RELATIVA (mismo origen que la página).
     *
     * Se devuelve sólo la ruta (`/storage/…`), sin host ni puerto: la foto se sirve desde el mismo origen que la vitrina
     * que la muestra, así funciona en cualquier host —el dev en un puerto, producción en su dominio— sin depender de que
     * `APP_URL` coincida con el puerto real. Se respeta el prefijo que el disco tenga configurado tomando sólo la parte de
     * ruta de la URL que arma el disco.
     */
    public function url(): string
    {
        return parse_url(Storage::disk('public')->url($this->disk_path), PHP_URL_PATH) ?: '/storage/'.ltrim($this->disk_path, '/');
    }
}
