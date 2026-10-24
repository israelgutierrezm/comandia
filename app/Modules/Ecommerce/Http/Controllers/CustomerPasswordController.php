<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Customers\Application\CustomerPasswordResets;
use App\Modules\Customers\Domain\Exceptions\CustomerPasswordResetInvalid;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use App\Modules\Ecommerce\Http\Requests\ForgotCustomerPasswordRequest;
use App\Modules\Ecommerce\Http\Requests\ResetCustomerPasswordRequest;
use App\Modules\Ecommerce\Jobs\SendCustomerPasswordResetLink;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Recuperar la contraseña de un cliente de la tienda (D381). Público, como el acceso, y con sus propios límites.
 *
 * Pedir el enlace responde SIEMPRE lo mismo y no toca `customers`: la búsqueda vive en el trabajo, así que ni el texto ni
 * el tiempo de respuesta dicen si el correo tiene cuenta. Restablecer NO inicia sesión —un enlace robado no se convierte
 * en una sesión abierta— y las demás sesiones del cliente caen en su siguiente petición (`CustomerSession`).
 *
 * Con la tienda apagada o el módulo desactivado todo responde 404, como el resto de `/t/{slug}`: no se recupera mientras
 * no se puede comprar.
 */
final class CustomerPasswordController
{
    use ResolvesPublicStore;

    public function __construct(private readonly CustomerPasswordResets $resets) {}

    public function forgot(ForgotCustomerPasswordRequest $request, string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $request->throttle((int) $store->tenant_id);

        // El correo como se tecleó: la base lo compara sin mayúsculas ni acentos. Quitárselos aquí con `Str::ascii` dejaba
        // sin enlace a quien se registró como `ǧabriel@…`, que se buscaba como `abriel@…` (D387).
        SendCustomerPasswordResetLink::dispatch(
            (int) $store->tenant_id,
            (int) $store->id,
            $request->string('email')->trim()->toString(),
        );

        return new JsonResponse(['data' => ['message' => sprintf(
            'Si ese correo tiene cuenta en %s, te enviamos un enlace para crear una contraseña nueva. Vence en %d minutos; '
            .'revisa también la carpeta de no deseado.',
            $store->name,
            CustomerPasswordResets::MINUTES_VALID,
        )]], 202);
    }

    public function reset(ResetCustomerPasswordRequest $request, string $slug): JsonResponse
    {
        $this->resolveStore($slug);

        $request->throttle();

        try {
            $this->resets->reset((string) $request->string('token'), (string) $request->string('password'));
        } catch (CustomerPasswordResetInvalid $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        return new JsonResponse(['data' => ['message' => 'Contraseña actualizada: entra con la nueva.']]);
    }
}
