<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use App\Modules\Ecommerce\Http\Requests\LoginCustomerRequest;
use App\Modules\Ecommerce\Http\Requests\RegisterCustomerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cuentas de cliente de la tienda en línea (Iteración 8, Tanda C). Registro, login y logout públicos, sin autenticación
 * de personal. El slug resuelve el negocio y fija el contexto, así que todo —incluida la búsqueda de credenciales— queda
 * acotado a ese tenant: un correo de un negocio jamás autentica en otro. El guard `customer` es aparte del `web`.
 *
 * Simplificaciones v1 declaradas: sin verificación de correo ni recuperación de contraseña (deuda: con el mailer por
 * negocio de la It.7).
 */
final class CustomerAuthController
{
    use ResolvesPublicStore;

    public function register(RegisterCustomerRequest $request, string $slug): JsonResponse
    {
        $this->resolveStore($slug); // fija el contexto del tenant

        $email = (string) $request->string('email');
        $phone = (string) $request->string('phone');

        // El teléfono es único por negocio: si ya existe, o es un cliente del POS sin credenciales que se activa, o ya
        // tiene cuenta.
        $byPhone = Customer::query()->where('phone', $phone)->first();

        if ($byPhone !== null && $byPhone->hasCredentials()) {
            throw new UnprocessableEntityHttpException('Ese teléfono ya tiene una cuenta. Inicia sesión.');
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
            $customer = Customer::create([
                'name' => (string) $request->string('name'),
                'phone' => $phone,
                'email' => $email,
                'password' => (string) $request->string('password'),
            ]);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return new JsonResponse(['data' => $this->present($customer)], 201);
    }

    public function login(LoginCustomerRequest $request, string $slug): JsonResponse
    {
        $this->resolveStore($slug);

        $ok = Auth::guard('customer')->attempt([
            'email' => (string) $request->string('email'),
            'password' => (string) $request->string('password'),
        ]);

        if (! $ok) {
            // Mismo mensaje para correo inexistente y contraseña mala: no se revela cuál falló.
            throw new UnprocessableEntityHttpException('Correo o contraseña incorrectos.');
        }

        $request->session()->regenerate();

        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        return new JsonResponse(['data' => $this->present($customer)]);
    }

    public function logout(Request $request, string $slug): JsonResponse
    {
        $this->resolveStore($slug);

        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return new JsonResponse(status: 204);
    }

    public function me(string $slug): JsonResponse
    {
        $this->resolveStore($slug);

        $customer = Auth::guard('customer')->user();

        return new JsonResponse(['data' => $customer instanceof Customer ? $this->present($customer) : null]);
    }

    /**
     * @return array{ulid: string, name: string, email: string|null}
     */
    private function present(Customer $customer): array
    {
        return ['ulid' => $customer->ulid, 'name' => $customer->name, 'email' => $customer->email];
    }
}
