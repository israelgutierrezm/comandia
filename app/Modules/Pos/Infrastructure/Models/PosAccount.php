<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Domain\Enums\PosAccountStatus;
use App\Modules\Pos\Domain\Enums\TakeoutDeliveryStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La cuenta: lo que se cobra (D28, §6.3).
 *
 * @property PosAccountStatus $status
 */
final class PosAccount extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_accounts';

    protected $fillable = [
        'branch_id',
        'series',
        'folio',
        'kind',
        'status',
        'table_id',
        'label',
        'customer_id',
        'takeout_number',
        'delivery_status',
        'waiter_membership_id',
        'pos_session_id',
        'opened_by_membership_id',
        'opened_at',
        'bill_requested_at',
        'closed_at',
        'paid_at',
        'cancelled_at',
        'cancelled_reason',
        'subtotal',
        'discount_total',
        'vat_total',
        'total',
        'paid_total',
        'tip_total',
        'change_total',
        'parent_account_id',
    ];

    protected $attributes = [
        'status' => 'open',
        'subtotal' => 0,
        'discount_total' => 0,
        'vat_total' => 0,
        'total' => 0,
        'paid_total' => 0,
        'tip_total' => 0,
        'change_total' => 0,
        'version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PosAccountStatus::class,
            'opened_at' => 'immutable_datetime',
            'bill_requested_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'takeout_number' => 'integer',
            'delivery_status' => TakeoutDeliveryStatus::class,
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * La mesa de la cuenta.
     *
     * ## Se llama `restaurantTable` y NO `table`, y hay una razón dolorosa
     *
     * `$table` es una propiedad de `Illuminate\Database\Eloquent\Model`: el nombre de la tabla de la base. Una relación
     * llamada `table()` no la sobrescribe —las relaciones se resuelven por `__get` sólo cuando el atributo no existe— así
     * que `$this->table` devolvía la CADENA `'pos_accounts'` en lugar de la mesa, y `$this->table->code` reventaba con
     * «Attempt to read property on string».
     *
     * Lo encontré con un 500 en la primera captura. Es la misma familia de problemas que hizo que la tabla se llame
     * `restaurant_tables` y no `tables`: «table» es una palabra tomada, tanto en MySQL como en Eloquent.
     *
     * @return BelongsTo<RestaurantTable, $this>
     */
    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    /**
     * @return BelongsTo<PosSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * El titular: a quien se le atribuyen las propinas (D233).
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'waiter_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'opened_by_membership_id');
    }

    /**
     * @return HasMany<PosOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'pos_account_id');
    }

    /**
     * Los items que se cobran en esta cuenta.
     *
     * Cuelgan de la CUENTA y no de sus órdenes, y por eso existe la columna denormalizada: mover un item entre cuentas
     * lo cambia de cuenta sin mover la orden, que describe lo que se preparó. Sin esta relación directa, «qué se cobra
     * aquí» exigiría recorrer órdenes cuyos items ya no le pertenecen.
     *
     * @return HasMany<PosOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosOrderItem::class, 'pos_account_id');
    }

    /**
     * Cómo se pagó esta cuenta.
     *
     * @return HasMany<PosPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class, 'pos_account_id');
    }

    /**
     * Los descuentos y cortesías de esta cuenta.
     *
     * @return HasMany<PosDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(PosDiscount::class, 'pos_account_id');
    }

    /**
     * La cuenta madre, si ésta es una parte de una división.
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    /**
     * Las partes en las que se dividió esta cuenta.
     *
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    /**
     * ¿Es una parte de una cuenta dividida?
     *
     * Importa en dos sitios: no tiene items propios —así que no se recalcula, o quedaría en cero— y su mesa la sigue
     * ocupando la madre.
     */
    public function isSplitPart(): bool
    {
        return $this->parent_account_id !== null;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * El folio como lo lee una persona.
     */
    public function folioNumber(): string
    {
        return sprintf('%s-%d', $this->series, $this->folio);
    }

    /**
     * Cómo se identifica esta cuenta ante quien la mira.
     *
     * La mesa si la tiene, el número de mostrador si es para llevar, la etiqueta libre si es de barra, y el folio como
     * último recurso. Se resuelve en el servidor porque la pantalla de piso, el ticket y la comanda tienen que decir lo
     * mismo — tres formateos distintos acaban diciendo tres cosas (D139).
     */
    public function displayName(): string
    {
        // Se mira `table_id` ANTES de tocar la relación. Sin esto, una cuenta de barra —que no tiene mesa— provocaba
        // una carga perezosa sólo para descubrir que no hay nada que cargar, y la carga perezosa está prohibida en este
        // proyecto: reventaba con `LazyLoadingViolationException` desde dentro de un mensaje de error, convirtiendo un
        // 409 explicativo en un 500.
        if ($this->table_id !== null) {
            return 'Mesa '.$this->restaurantTable?->code;
        }

        if ($this->takeout_number !== null) {
            return 'Para llevar #'.$this->takeout_number;
        }

        return $this->label ?? $this->folioNumber();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PosAccountStatus::Open->value,
            PosAccountStatus::BillRequested->value,
            PosAccountStatus::Closed->value,
        ]);
    }
}
