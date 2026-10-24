<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Support;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Shared\Http\Support\SessionPasswordFingerprint;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use LogicException;

/**
 * La sesión del cliente de la tienda en línea (D381). Es el ÚNICO lector del guardia `customer` (lo impone
 * `CustomerSessionIsCentralizedTest`).
 *
 * Guarda la huella de la contraseña al entrar y la revisa en cada lectura: restablecer la contraseña —o que el negocio
 * inactive al cliente— saca a cualquier otra sesión suya en su siguiente petición.
 *
 * No es middleware porque la sesión del cliente sólo se puede leer DESPUÉS de `resolveStore`: sin el contexto del negocio,
 * el `TenantScope` de `Customer` lanza.
 *
 * Nunca hace `flush()` ni `invalidate()` al sacar a alguien: la cookie de la tienda es la misma que la del personal (grupo
 * `public`) y el carrito vive en esa sesión. Sólo olvida al cliente.
 */
final class CustomerSession
{
    private const GUARD = 'customer';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly SessionPasswordFingerprint $fingerprint,
    ) {}

    /**
     * Abre la sesión de un cliente que acaba de registrarse.
     */
    public function start(Request $request, Customer $customer): void
    {
        $this->guard()->login($customer);
        $request->session()->regenerate();

        $this->fingerprint->store($request, $this->guard(), self::GUARD);
    }

    /**
     * Abre la sesión con correo y contraseña. Un cliente inactivo no entra, y recibe lo mismo que una contraseña mala.
     */
    public function attempt(Request $request, string $email, string $password): ?Customer
    {
        $guard = $this->guard();

        if (! $guard->attempt(['email' => $email, 'password' => $password, 'status' => 'active'])) {
            return null;
        }

        $request->session()->regenerate();
        $this->fingerprint->store($request, $guard, self::GUARD);

        /** @var Customer */
        return $guard->user();
    }

    /**
     * El cliente de esta sesión, o null. Si su contraseña cambió desde que entró, o el negocio lo inactivó, la sesión lo
     * olvida aquí mismo.
     */
    public function current(Request $request): ?Customer
    {
        $guard = $this->guard();
        $customer = $guard->user();

        if (! $customer instanceof Customer) {
            return null;
        }

        $vigente = $customer->isActive()
            && $customer->hasCredentials()
            && $this->fingerprint->sessionMatches($request, $guard, self::GUARD, (string) $customer->getAuthPassword());

        if (! $vigente) {
            $guard->logoutCurrentDevice();
            $request->session()->forget($this->fingerprint->key(self::GUARD));

            return null;
        }

        return $customer;
    }

    /**
     * Cierra la sesión del cliente. Es el comportamiento de siempre: invalida la sesión compartida (pendiente declarado
     * de D381).
     */
    public function end(Request $request): void
    {
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function guard(): SessionGuard
    {
        $guard = $this->auth->guard(self::GUARD);

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('El guardia de clientes debe ser de sesión.');
        }

        return $guard;
    }
}
