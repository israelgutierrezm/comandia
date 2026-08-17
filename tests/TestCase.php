<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Caso base de todas las suites.
 *
 * A partir de la Iteración 1 aquí vivirán las utilidades de contexto de prueba
 * (actuar como un usuario dentro de un tenant, con rol activo y sucursal
 * activa), y el caso base de los tests de aislamiento de tenant exigidos en la
 * definition of done de cada módulo.
 */
abstract class TestCase extends BaseTestCase
{
    //
}
