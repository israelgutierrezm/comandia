<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * Modelo de prueba con identificador público, tal como se declararán las
 * entidades del kernel expuestas por API.
 */
final class UlidFixture extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'ulid_fixtures';

    protected $fillable = ['name'];
}
