<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use App\Modules\Ecommerce\Http\Requests\LoginCustomerRequest;
use App\Modules\Ecommerce\Http\Requests\RegisterCustomerRequest;
use App\Modules\Ecommerce\Http\Support\CustomerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cuentas de cliente de la tienda en línea (Iteración 8, Tanda C). Registro, login y logout públicos, sin autenticación
 * de personal. El slug resuelve el negocio y fija el contexto, así que todo —incluida la búsqueda de credenciales— queda
 * acotado a ese tenant: un correo de un negocio jamás autentica en otro. El guard `customer` es aparte del `web`.
 *
 * La sesión la lleva {@see CustomerSession}: guarda la huella de la contraseña y la revisa en cada lectura, así que
 * restablecer la contraseña o inactivar al cliente cierra sus otras sesiones (D381). Un cliente inactivo no entra.
 *
 * Simplificación v1 declarada: sin verificación de correo (deuda de D333); el registro todavía dice si un correo o un
 * teléfono ya tiene cuenta, ahora con tope por IP.
 */
final class CustomerAuthController
{
    use ResolvesPublicStore;

    private const PHONE_TAKEN = 'No se puede crear una cuenta con ese teléfono. Si ya tienes cuenta, inicia sesión; si no, '
        .'comunícate con el negocio.';

    public function __construct(private readonly CustomerSession $session) {}

    public function register(RegisterCustomerRequest $request, string $slug): JsonResponse
    {
        $this->resolveStore($slug); // fija el contexto del tenant

        $request->throttle();

        $email = (string) $request->string('email');
        $phone = (string) $request->string('phone');

        // El teléfono es único por negocio: si ya existe, o es un cliente del POS sin credenciales que se activa, o ya
        // tiene cuenta.
        $byPhone = Customer::query()->where('phone', $phone)->first();

        // Un cliente que el negocio inactivó tampoco recibe credenciales que no podría usar (D381). El mensaje es el mismo
        // que el de «ya tiene cuenta»: si fuera otro, el registro diría qué teléfonos son de clientes inactivados.
        if ($byPhone !== null && ($byPhone->hasCredentials() || ! $byPhone->isActive())) {
            throw new UnprocessableEntityHttpException(self::PHONE_TAKEN);
        }

        // El correo es la llave de acceso: único por negocio.
        $emailOwner = Customer::query()->where('email', $email)->first();

        if ($emailOwner !== null && ($byPhone === null || $emailOwner->id !== $byPhone->id)) {
            throw new UnprocessableEntityHttpException('Ese correo ya está registrado.');
        }

        if ($byPhone !== null) {
            // Cliente del POS (D43): se le activan las credenciales.
            $byPhone->update([
                'name' => (string) $request->string('name'),
                'email' => $email,
                'password' => (string) $request->string('password'),
            ]);
            $customer = $byPhone;
        } else {
            // Se relee: el estado lo pone la base, y la sesión revisa que el cliente esté activo (D381).
            $customer = Customer::create([
                'name' => (string) $request->string('name'),
                'phone' => $phone,
                'email' => $email,
                'password' => (string) $request->string('password'),
            ])->refresh();
        }

        $this->session->start($request, $customer);

        return new JsonResponse(['data' => $this->present($customer)], 201);
    }

    public function login(LoginCustomerRequest $request, string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);
        $tenantId = (int) $store->tenant_id;

        $request->consumeLoginAttempt($tenantId);

        $customer = $this->session->attempt(
            $request,
            (string) $request->string('email'),
            (string) $request->string('password'),
        );

        if ($customer === null) {
            // Mismo mensaje para correo inexistente, contraseña mala y cliente inactivo: no se revela cuál falló.
            throw new UnprocessableEntityHttpException('Correo o contraseña incorrectos.');
        }

        $request->clearRateLimiter($tenantId);

        return new JsonResponse(['data' => $this->present($customer)]);
    }

    public function logout(Request $request, string $slug): JsonResponse
    {
        $this->resolveStore($slug);

        $this->session->end($request);

        return new JsonResponse(status: 204);
    }

    public function me(Request $request, string $slug): JsonResponse
    {
        $this->resolveStore($slug);

        $customer = $this->session->current($request);

        return new JsonResponse(['data' => $customer === null ? null : $this->present($customer)]);
    }

    /**
     * @return array{ulid: string, name: string, email: string|null}
     */
    private function present(Customer $customer): array
    {
        return ['ulid' => $customer->ulid, 'name' => $customer->name, 'email' => $customer->email];
    }
}
