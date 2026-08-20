<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Middleware;

use App\Modules\Printing\Infrastructure\Models\PrintAgent;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al agente de impresión por su token.
 *
 * ## Por qué NO es Sanctum
 *
 * Sanctum autentica **usuarios**, y un agente no lo es (§9.3): no tiene rol activo, no tiene permisos y no opera nada.
 * Colgarle un usuario sería lo cómodo —el mecanismo ya existe— y le abriría la API entera a un proceso que corre sin
 * vigilancia en una computadora de cocina que cualquiera puede tocar. Un token robado de ahí podría consultar ventas,
 * cambiar precios o cancelar cuentas.
 *
 * Con esto, un token robado sólo puede pedir e imprimir los trabajos de **su** sucursal. Es el daño mínimo, y es la
 * razón entera de que este middleware exista en lugar de reusar lo que ya había.
 *
 * ## El tenant sale del AGENTE, nunca de la petición
 *
 * ADR-002 dice que el `tenant_id` se resuelve del token y jamás llega como parámetro del cliente. Aquí el token es el
 * del agente, y el tenant y la sucursal salen de su fila. Un agente no puede pedir trabajos de otro negocio ni aunque
 * lo intente: no hay ningún parámetro por el que pedirlo.
 *
 * ## Se busca por HASH y no comparando uno por uno
 *
 * El token se hashea y se busca la fila por índice único. La alternativa —traer todos los agentes y comparar con
 * `password_verify`— es lo que se hace con contraseñas, donde el hash lleva sal por fila. Aquí sería un recorrido de
 * tabla en cada sondeo de cada agente, cada pocos segundos, todo el día.
 *
 * El precio de usar un hash sin sal es que dos tokens iguales darían el mismo hash; no importa, porque el token lo
 * genera el servidor con 32 bytes aleatorios y la colisión no es un escenario. Lo que sí importa es que un volcado de
 * la base no entregue tokens usables, y eso se cumple.
 */
final class AuthenticatePrintAgent
{
    public const ATRIBUTO = 'print_agent';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? (string) $request->header('X-Print-Agent-Token', '');

        if ($token === '') {
            return $this->rechazo('Este endpoint es para agentes de impresión y requiere su token.');
        }

        $agent = PrintAgent::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($agent === null || ! $agent->isActive()) {
            return $this->rechazo('El token del agente de impresión no es válido o el agente está desactivado.');
        }

        // El contexto se pone AQUÍ y desde el agente. Todo lo que corra después ve sólo su negocio, con el mismo global
        // scope que protege al resto del sistema.
        app(TenantContext::class)->set((int) $agent->tenant_id);

        $request->attributes->set(self::ATRIBUTO, $agent);

        return $next($request);
    }

    private function rechazo(string $mensaje): JsonResponse
    {
        return new JsonResponse([
            'type' => 'unauthenticated',
            'title' => $mensaje,
            'status' => 401,
        ], 401);
    }
}
