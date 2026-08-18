<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lote de un artículo, con su caducidad (D23).
 *
 * Opcional por artículo. El lote pertenece al **artículo** y no al artículo en un almacén (P3): el mismo lote
 * de leche puede estar repartido entre dos sucursales y su caducidad es la misma en las dos.
 *
 * La lógica de FEFO —elegir lotes al dar salida— llega en el paso 3 de la iteración. Esta clase y su tabla
 * existen desde el paso 1 porque de ellas depende la unicidad del saldo: la llave de `article_stocks` incluye
 * el lote.
 *
 * @property string $code
 */
final class ArticleLot extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'article_lots';

    protected $fillable = [
        'article_id',
        'code',
        'expires_at',
        'received_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => LotStatus::class,
            'expires_at' => 'immutable_date',
            'received_at' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        // El artículo y el código de un lote NO cambian nunca, por la misma razón que la unidad base de un
        // artículo (D96) y la cantidad de una presentación (D147): los movimientos de inventario ya lo citan,
        // y reasignarlo reinterpretaría existencias que ya se movieron.
        //
        // La caducidad SÍ se puede corregir: es un dato del envase que se pudo teclear mal, y corregirlo no
        // reinterpreta ningún movimiento pasado — sólo cambia el orden en que saldrá lo que queda.
        self::updating(function (self $lot): void {
            foreach (['article_id', 'code'] as $inmutable) {
                if ($lot->isDirty($inmutable)) {
                    throw new \RuntimeException(
                        "El campo «{$inmutable}» de un lote no se puede cambiar: los movimientos de "
                        .'inventario ya lo citan. Crea otro lote.'
                    );
                }
            }
        });
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Los lotes que pueden surtir una salida, en orden FEFO: primero lo que caduca.
     *
     * `expires_at` ascendente con los `NULL` AL FINAL, que es la parte que no se adivina: en MySQL los `NULL`
     * ordenan primero, así que un artículo que no caduca se saldría antes que uno que sí — exactamente lo
     * contrario de lo que FEFO quiere.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFefo(Builder $query, int $articleId): Builder
    {
        return $query
            ->where('article_id', $articleId)
            ->where('status', LotStatus::Active->value)
            ->orderByRaw('`expires_at` IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id');
    }

    /** ¿Ya caducó a la fecha dada? */
    public function hasExpiredBy(\DateTimeInterface $date): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThan($date);
    }
}
