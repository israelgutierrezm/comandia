<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * Modelo de prueba que hereda de la base de dominio real.
 *
 * Al extender `DomainModel` usa el `BelongsToTenant` y el `TenantScope` de
 * verdad, así que el test estructural no verifica un doble: verifica el mecanismo
 * que usarán los modelos del kernel.
 *
 * Su tabla existe sólo en la migración de pruebas
 * (`tests/Fixtures/database/create_fixture_tables.php`).
 */
final class ScopedFixture extends DomainModel
{
    protected $table = 'scoped_fixtures';

    protected $fillable = ['name'];
}
