<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Domain\Exceptions\CustomerPasswordResetInvalid;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerPasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los enlaces para que un cliente de la tienda cree una contraseña nueva (D381).
 *
 * Un enlace vivo por cliente (lo impone la base), de un solo uso y 60 minutos. El token nace aquí y sólo existe en claro
 * en el correo; la tabla guarda su SHA-256.
 *
 * El enlace sólo vale si el correo del cliente SIGUE siendo al que se mandó: si el negocio le cambió el correo después,
 * el enlace muere aunque nadie lo haya revocado. Y sólo para clientes activos con contraseña.
 *
 * Todo corre bajo el `TenantScope` del negocio que fijó el slug: el token de otra tienda no existe aquí. No emite eventos
 * de dominio: nadie fuera de Customers escucha.
 *
 * Los dos métodos que escriben toman los candados en el mismo orden —primero el cliente, luego su enlace—: en orden
 * inverso, pedir un enlace y usar el anterior al mismo tiempo se interbloqueaban.
 */
final class CustomerPasswordResets
{
    public const MINUTES_VALID = 60;

    /** Un segundo pedido dentro de este lapso no manda otro correo: el enlace anterior sigue sirviendo. */
    public const COOLDOWN_SECONDS = 300;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Emite un enlace nuevo para el correo que el cliente tiene en `$customer` y devuelve el token en claro, o null si ya
     * se emitió uno hace menos de 5 minutos o si el cliente ya no está como se leyó.
     */
    public function issue(Customer $customer): ?string
    {
        return DB::transaction(function () use ($customer): ?string {
            // El candado del cliente ordena dos pedidos simultáneos: el segundo ve el enlace del primero y respeta el
            // enfriamiento, en vez de chocar contra `unique(customer_id)`.
            $cliente = Customer::query()->whereKey($customer->id)->lockForUpdate()->sole();

            // Quien llama va a mandar el correo al buzón que leyó ANTES de este candado. Si entre esa lectura y ésta el
            // negocio le cambió el correo (y su revocación no encontró nada que borrar, porque el enlace aún no existía),
            // el enlace quedaría atado al correo nuevo y viajaría al viejo. Lo mismo si lo inactivaron.
            $mismo = $this->isEligible($cliente)
                && mb_strtolower((string) $cliente->email) === mb_strtolower((string) $customer->email);

            if (! $mismo) {
                return null;
            }

            $vigente = CustomerPasswordReset::query()->where('customer_id', $cliente->id)->first();

            if ($vigente !== null && $vigente->created_at->gt(now()->subSeconds(self::COOLDOWN_SECONDS))) {
                return null;
            }

            $vigente?->delete();

            $token = Str::random(64);

            CustomerPasswordReset::create([
                'customer_id' => $cliente->id,
                'email' => (string) $cliente->email,
                'token_hash' => CustomerPasswordReset::hashToken($token),
                'expires_at' => now()->addMinutes(self::MINUTES_VALID),
            ]);

            return $token;
        });
    }

    /**
     * Guarda la contraseña nueva con el enlace y lo consume. Cualquier falla —vencido, usado, reemplazado, de otro
     * correo, de un cliente inactivo o sin cuenta— lanza la misma excepción: el mensaje no dice cuál fue.
     *
     * @throws CustomerPasswordResetInvalid
     */
    public function reset(string $token, string $password): Customer
    {
        $hash = CustomerPasswordReset::hashToken($token);

        return DB::transaction(function () use ($hash, $password): Customer {
            // Primero el cliente y luego el enlace, como en `issue()`: la primera lectura, sin candado, sólo dice de qué
            // cliente es; la segunda, ya con los dos candados, es la que vale.
            $clienteId = CustomerPasswordReset::query()->where('token_hash', $hash)->value('customer_id');

            if ($clienteId === null) {
                throw new CustomerPasswordResetInvalid();
            }

            $cliente = Customer::query()->whereKey($clienteId)->lockForUpdate()->first();

            $enlace = CustomerPasswordReset::query()
                ->where('token_hash', $hash)
                ->where('customer_id', $clienteId)
                ->lockForUpdate()
                ->first();

            $vale = $enlace !== null
                && $enlace->expires_at->isFuture()
                && $cliente !== null
                && $this->isEligible($cliente)
                && mb_strtolower((string) $cliente->email) === mb_strtolower($enlace->email);

            if (! $vale) {
                throw new CustomerPasswordResetInvalid();
            }

            $cliente->update(['password' => $password]);
            $enlace->delete();

            $this->audit->log(
                action: AuditAction::CUSTOMER_PASSWORD_RESET,
                auditable: $cliente,
                after: ['via' => 'enlace_correo'],
            );

            return $cliente->refresh();
        });
    }

    /**
     * Sólo un cliente activo, con cuenta y con correo tiene a dónde recibir un enlace.
     */
    private function isEligible(Customer $cliente): bool
    {
        return $cliente->isActive() && $cliente->hasCredentials() && $cliente->email !== null;
    }

    /**
     * Mata el enlace vivo del cliente, si lo hay: al cambiarle el correo o inactivarlo.
     */
    public function revokeFor(Customer $customer): void
    {
        CustomerPasswordReset::query()->where('customer_id', $customer->id)->delete();
    }
}
