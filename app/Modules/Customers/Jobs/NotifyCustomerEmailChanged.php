<?php

declare(strict_types=1);

namespace App\Modules\Customers\Jobs;

use App\Modules\Configuration\Application\TenantMailer;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Mail\CustomerEmailChangedMail;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Support\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa al correo anterior de un cliente con cuenta que el negocio le cambió el correo (D381).
 *
 * En cola (`default`): guardar la ficha no espera a un servidor de correo, y un SMTP caído no deshace el cambio. Abre el
 * contexto del negocio porque `TenantMailer` lee su configuración de correo. El correo nuevo se lee al enviar: si lo
 * volvieron a cambiar, el aviso dice el último.
 */
final class NotifyCustomerEmailChanged implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $customerId,
        private readonly string $previousEmail,
    ) {
        $this->onQueue(Queue::Default->value);
    }

    public function handle(TenantContext $tenants, TenantMailer $mailer): void
    {
        $tenants->runFor($this->tenantId, function () use ($mailer): void {
            $cliente = Customer::query()->find($this->customerId);

            if ($cliente === null || $cliente->email === null) {
                return;
            }

            $negocio = (string) Tenant::query()->whereKey($this->tenantId)->value('name');
            $config = $mailer->settings();

            $mailer->send($this->previousEmail, new CustomerEmailChangedMail(
                businessName: $negocio,
                maskedNewEmail: CustomerEmailChangedMail::mask($cliente->email),
                fromAddress: $config?->from_address,
                fromName: $config?->from_name,
            ));
        });
    }
}
