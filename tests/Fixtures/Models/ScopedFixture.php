<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Scopes\TenantScope;

/**
 * Modelo de prueba CON scope de tenant. Nunca toca la base de datos.
 */
#[ScopedBy(TenantScope::class)]
final class ScopedFixture extends Model
{
    protected $table = 'scoped_fixtures';
}
