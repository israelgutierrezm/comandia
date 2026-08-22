<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un perfil fiscal del cliente: los datos que un CFDI necesita (ADR-005). 0..N por cliente.
 *
 * No es inmutable: un RFC mal tecleado se corrige, y corregirlo no reinterpreta ninguna venta pasada —lo que una venta
 * cita es al cliente, no su RFC— (mismo criterio que el proveedor). Los datos ya usados en un CFDI timbrado quedarán
 * congelados en el propio comprobante cuando exista el timbrado; hoy no hay comprobante que proteger.
 */
final class CustomerFiscalProfile extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'customer_fiscal_profiles';

    protected $fillable = [
        'customer_id',
        'rfc',
        'person_type',
        'business_name',
        'postal_code',
        'tax_regime_code',
        'cfdi_use_code',
        'is_default',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
