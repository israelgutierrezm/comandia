<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio y disponibilidad propios de una sucursal (§6.1).
 *
 * `NULL` en una columna significa **heredar** el dato maestro del artículo, igual que en la cascada de
 * configuración del kernel. Una fila con las dos columnas en NULL no debe existir: el servicio la borra,
 * porque es indistinguible de no tener override.
 *
 * **No lleva ULID**: no es un recurso propio de la API. Se administra por la URL del artículo y la sucursal
 * —`/articles/{ulid}/branches/{branch}/…`— que son los recursos que el cliente conoce. Un identificador
 * público invitaría a tratarlo como entidad independiente, y no lo es: es la excepción de una.
 *
 * @property numeric-string|null $price
 * @property bool|null $is_available_in_pos
 */
final class ArticleBranchOverride extends DomainModel
{
    protected $table = 'article_branch_overrides';

    protected $fillable = [
        'article_id',
        'branch_id',
        'price',
        'is_available_in_pos',
    ];

    protected function casts(): array
    {
        return [
            // Nullable a propósito: `boolean` castea NULL a NULL, no a false. Si lo hiciera, "hereda" se
            // volvería "no disponible" y un platillo desaparecería de una sucursal sin que nadie lo pidiera.
            'is_available_in_pos' => 'boolean',

            // `price` sin cast a float: es un monto que entra en aritmética `bcmath` (§7, P3).
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * ¿Esta fila ya no anula nada?
     *
     * Si las dos columnas heredan, la fila sobra. Lo pregunta el servicio para borrarla en lugar de dejar un
     * override vacío que después haría ambigua la pregunta "¿esta sucursal tiene precio propio?".
     */
    public function overridesNothing(): bool
    {
        return $this->price === null && $this->is_available_in_pos === null;
    }
}
