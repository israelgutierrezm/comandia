<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Límites propios de las cuentas de la tienda (D381, ARQUITECTURA §8): acceso, registro, pedir el enlace y restablecer.
 *
 * El `throttle:public` del grupo (300 por minuto por IP) está pensado para una mesa que escanea el mismo QR, no para frenar
 * a quien prueba contraseñas. Cada pedido lleva su llave, que incluye al negocio cuando ya se resolvió por el slug: un
 * correo que se repite entre negocios no comparte contador.
 *
 * El intento se cuenta ANTES de atenderlo y de forma atómica (`hit` devuelve la cuenta nueva): si sólo se leyera el
 * contador y se sumara al final, una ráfaga de peticiones simultáneas pasaría completa mientras ninguna ha terminado.
 *
 * El 429 lleva el mensaje en `title` con los minutos de espera, y se calcula igual exista o no la cuenta.
 */
trait ThrottlesStoreRequests
{
    /**
     * Cuenta el intento y rechaza si con él se pasa del tope.
     *
     * @throws TooManyRequestsHttpException
     */
    protected function consumeAttempt(string $key, int $maxAttempts, int $decaySeconds, string $reason): void
    {
        if (RateLimiter::hit($key, $decaySeconds) <= $maxAttempts) {
            return;
        }

        $segundos = RateLimiter::availableIn($key);
        $minutos = max(1, (int) ceil($segundos / 60));

        throw new TooManyRequestsHttpException($segundos, sprintf(
            '%s Vuelve a intentarlo en %d %s.',
            $reason,
            $minutos,
            $minutos === 1 ? 'minuto' : 'minutos',
        ));
    }

    /**
     * El correo como lo compara la base: sin mayúsculas ni acentos. `customers.email` usa una intercalación que no
     * distingue acentos (`ēma@…` encuentra a `ema@…`); si la llave sí los distinguiera, cada variante estrenaría su
     * propio contador sobre la misma cuenta. El limitador de Laravel ya limpia las letras de Latin-1 (`é`, `ñ`) al armar
     * su llave, pero no las demás (`ē`, `ł`, `ğ`), que la base también iguala.
     */
    public function normalizedEmail(): string
    {
        return Str::ascii(mb_strtolower(trim((string) $this->input('email'))));
    }
}
