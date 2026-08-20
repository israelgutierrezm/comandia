<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El límite y el saldo de un cliente.
 *
 * `balance` es **proyección**, no verdad: se reconstruye de los movimientos, igual que `article_stocks` frente al
 * kardex. Existe para no sumar la historia en cada consulta, no para ser la fuente.
 */
final class CustomerCredit extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'customer_credits';

    protected $fillable = [
        'customer_id',
        'credit_limit',
        'balance',
        'is_enabled',
    ];

    protected $attributes = [
        'credit_limit' => 0,
        'balance' => 0,
        'is_enabled' => true,
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Cuánto puede fiar todavía.
     *
     * @return numeric-string
     */
    public function available(): string
    {
        $disponible = bcsub((string) $this->credit_limit, (string) $this->balance, 2);

        // Nunca negativo hacia fuera: un cliente que se pasó del límite —por un ajuste, por una autorización— tiene
        // cero disponible, no «menos doscientos disponibles», que no significa nada para quien lo lee en el mostrador.
        return bccomp($disponible, '0', 2) < 0 ? '0.00' : $disponible;
    }

    /** ¿Puede fiar este monto ahora mismo? */
    public function allows(string $amount): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        return bccomp(bcadd((string) $this->balance, $amount, 2), (string) $this->credit_limit, 2) <= 0;
    }
}
