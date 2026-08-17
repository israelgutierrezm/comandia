<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de prueba SIN scope de tenant. Nunca toca la base de datos.
 *
 * Su única razón de existir es que el test estructural pueda comprobar que su
 * detector reprueba lo que debe reprobar. Sin este doble, el test pasaría igual
 * de verde estando roto, que es la peor forma de tener una red de seguridad.
 */
final class UnscopedFixture extends Model
{
    protected $table = 'unscoped_fixtures';
}
