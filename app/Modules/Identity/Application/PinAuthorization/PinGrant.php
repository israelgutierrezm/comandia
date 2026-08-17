<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\PinAuthorization;

use Illuminate\Support\Carbon;

/**
 * Autorización concedida por PIN: de un solo uso, ligada a la acción y con expiración
 * (ADR-008, límite 3).
 *
 * La terminal permanece abierta; la autorización no. Es la diferencia entre "el gerente
 * autorizó este descuento" y "el gerente dejó la terminal en modo gerente" — la segunda es
 * la que convierte un control en un teatro.
 */
final readonly class PinGrant
{
    public function __construct(
        public string $token,
        public string $permission,
        public string $authorizerUlid,
        public string $authorizerName,
        public Carbon $expiresAt,
    ) {}

    public function secondsToExpire(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expiresAt, absolute: false));
    }
}
