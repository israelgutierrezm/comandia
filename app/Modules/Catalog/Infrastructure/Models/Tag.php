<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Etiqueta libre del tenant (D19).
 *
 * Sin `status`, al contrario que el resto del catálogo: una etiqueta que ya no se usa se borra y el
 * pivote cae con ella. No aparece en ningún documento, así que no hay histórico que preservar y D80
 * no aplica.
 *
 * @property string $name
 */
final class Tag extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'tags';

    protected $fillable = ['name'];

    /**
     * @return BelongsToMany<Article, $this>
     *
     * @see Article::tags()  el porqué de `withPivotValue`
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_tag')
            ->withPivotValue('tenant_id', app(TenantContext::class)->id());
    }
}
