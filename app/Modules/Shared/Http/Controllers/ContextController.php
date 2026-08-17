<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Resources\RequestContextResource;

/**
 * "¿Quién soy y qué puedo hacer aquí?"
 *
 * Primer endpoint del contexto operativo. Lo consumen por igual la SPA —al arrancar el
 * shell y al cambiar de rol o sucursal— y la app Flutter, que es exactamente la
 * simetría que ARQUITECTURA_MAESTRA §8 pide: una sola API.
 *
 * No recibe parámetros: todo lo que necesita ya lo resolvió el middleware desde la
 * sesión o el token, más los headers de contexto operativo validados. Que este endpoint
 * no tenga entrada es la prueba de que el contexto no se negocia con el cliente.
 */
final class ContextController
{
    public function __invoke(ContextHolder $holder): RequestContextResource
    {
        return new RequestContextResource($holder->get());
    }
}
