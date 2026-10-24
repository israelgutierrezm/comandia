<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Configuration\Application\TenantMailer;
use App\Modules\Customers\Application\CustomerPasswordResets;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Ecommerce\Mail\CustomerPasswordResetMail;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Support\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Busca al cliente y le manda el enlace para crear una contraseña nueva (D381).
 *
 * La búsqueda vive aquí y no en la petición: la tienda responde lo mismo, y en el mismo tiempo, exista o no la cuenta.
 * Sólo reciben enlace los clientes activos con contraseña; un cliente del POS sin cuenta no se activa por correo (su
 * correo lo tecleó el personal y nunca se verificó).
 *
 * El token nace aquí y sólo viaja en el correo: no va en la carga útil del trabajo. El enlace se arma sobre `app.url` y el
 * slug leído de la base al enviar, nunca sobre el `Host` de la petición.
 *
 * Si el envío falla, el enlace se revoca antes de relanzar: el reintento emite otro, y nunca quedan dos vivos.
 */
final class SendCustomerPasswordResetLink implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $storeId,
        private readonly string $email,
    ) {
        $this->onQueue(Queue::Default->value);
    }

    public function handle(TenantContext $tenants, TenantMailer $mailer, CustomerPasswordResets $resets, ModuleGate $modules): void
    {
        $tenants->runFor($this->tenantId, function () use ($mailer, $resets, $modules): void {
            // La tienda pudo apagarse, o el módulo desactivarse, entre la petición y el trabajo: sin tienda no hay a dónde
            // mandar al cliente.
            $tienda = Store::query()->whereKey($this->storeId)->where('is_active', true)->first();

            if ($tienda === null || ! $modules->isEnabled('Ecommerce')) {
                return;
            }

            $cliente = Customer::query()
                ->active()
                ->whereNotNull('password')
                ->where('email', $this->email)
                ->first();

            if ($cliente === null) {
                return;
            }

            $token = $resets->issue($cliente);

            // Dentro del enfriamiento: el enlace anterior sigue sirviendo y no sale otro correo.
            if ($token === null) {
                return;
            }

            $enlace = rtrim((string) config('app.url'), '/').'/t/'.$tienda->slug.'/restablecer/'.$token;
            $config = $mailer->settings();

            try {
                $mailer->send((string) $cliente->email, new CustomerPasswordResetMail(
                    storeName: (string) $tienda->name,
                    customerName: (string) $cliente->name,
                    link: $enlace,
                    minutes: CustomerPasswordResets::MINUTES_VALID,
                    fromAddress: $config?->from_address,
                    fromName: $config?->from_name,
                ));
            } catch (Throwable $e) {
                $resets->revokeFor($cliente);

                throw $e;
            }
        });
    }
}
