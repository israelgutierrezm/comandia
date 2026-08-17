<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\ValueObjects\PersonName;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capa 3 de identidad: el perfil laboral por tenant.
 *
 * Base del futuro módulo de nómina y —por D66— **la fuente del nombre de toda
 * persona sin credenciales de acceso**. De ahí que los tres campos de nombre legal
 * no sean opcionales en la práctica: si esta tabla es el respaldo del nombre, no
 * puede estar a medias.
 *
 * CURP, RFC y NSS son datos personales sensibles y viven en claro (D77): el RFC
 * tiene que ser buscable para CFDI y su unicidad verificable por índice. La
 * protección es el permiso `identity.employee_profiles.view_sensitive` más
 * auditoría de lectura, no el cifrado. Por eso van en `$hidden`: no se exponen por
 * accidente en una respuesta, sólo cuando un Resource los pide explícitamente
 * tras verificar el permiso.
 */
final class EmployeeProfile extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'employee_profiles';

    protected $fillable = [
        'membership_id',
        'legal_first_name',
        'legal_paternal_surname',
        'legal_maternal_surname',
        'is_foreigner',
        'curp',
        'rfc',
        'nss',
        'birth_date',
        'hired_at',
        'terminated_at',
    ];

    /**
     * PII sensible fuera de la serialización por defecto (D77).
     */
    protected $hidden = ['curp', 'rfc', 'nss', 'birth_date'];

    protected function casts(): array
    {
        return [
            'is_foreigner' => 'boolean',
            'birth_date' => 'immutable_date',
            'hired_at' => 'immutable_date',
            'terminated_at' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /**
     * Nombre legal, para nómina, CFDI y auditoría.
     */
    public function legalName(): PersonName
    {
        return new PersonName(
            $this->legal_first_name,
            $this->legal_paternal_surname,
            $this->legal_maternal_surname,
        );
    }
}
