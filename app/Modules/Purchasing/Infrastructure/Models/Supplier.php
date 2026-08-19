<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un proveedor (D26).
 *
 * Se da de BAJA, no se borra: sus recepciones y su historial de precios lo citan, y un proveedor borrado dejaría
 * compras sin poder decir a quién se le compraron. Las FK del historial son RESTRICT justamente para que la base lo
 * impida además de la costumbre.
 *
 * @property string $code
 * @property string $legal_name
 */
final class Supplier extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'suppliers';

    protected $fillable = [
        'code',
        'legal_name',
        'trade_name',
        'rfc',
        'contact_name',
        'phone',
        'email',
        'payment_terms_days',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'payment_terms_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // El CÓDIGO no cambia. Es la misma razón que el código de un lote (D23) o la unidad base de un artículo (D96):
        // es el identificador con el que la gente lo llama en papeles y conversaciones, y reasignarlo haría que los
        // documentos viejos parecieran ser de otro proveedor.
        //
        // Todo lo demás sí se corrige: un teléfono cambia, una razón social se teclea mal, y corregirlos no
        // reinterpreta ninguna compra pasada.
        self::updating(function (self $supplier): void {
            if ($supplier->isDirty('code')) {
                throw new \RuntimeException(
                    'El código de un proveedor no se puede cambiar: es como lo identifican los documentos ya '
                    .'capturados. Da de baja este proveedor y crea otro si hace falta.'
                );
            }
        });
    }

    /** @return HasMany<SupplierPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(SupplierPrice::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** Como lo llama la gente: el nombre comercial si lo tiene, la razón social si no. */
    public function displayName(): string
    {
        return $this->trade_name ?? $this->legal_name;
    }
}
