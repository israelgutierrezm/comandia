<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\PinAuthorization;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Autorización por PIN rechazada.
 *
 * Los mensajes son **deliberadamente indistinguibles** entre "ese empleado no existe",
 * "el PIN es incorrecto" y "esa persona no tiene el permiso". Distinguirlos convertiría el
 * endpoint en un oráculo: permitiría enumerar códigos de empleado válidos y averiguar quién
 * puede autorizar qué, tecleando PIN al azar.
 *
 * La única excepción es el bloqueo, que sí se comunica: la persona necesita saber por qué
 * su PIN dejó de funcionar, y para entonces ya demostró conocer un código válido.
 *
 * El motivo real de cada rechazo queda en la bitácora, donde lo lee quien tiene permiso de
 * auditoría.
 */
final class PinAuthorizationFailed extends HttpException
{
    public static function invalid(): self
    {
        return new self(422, 'Código de empleado o PIN incorrectos.');
    }

    public static function locked(int $minutes): self
    {
        return new self(423, sprintf(
            'El PIN quedó bloqueado por intentos fallidos. Vuelve a intentarlo en %d minuto(s).',
            max(1, $minutes),
        ));
    }

    public static function notAuthorized(): self
    {
        // Mismo código y mismo texto que `invalid()`: quien teclea no debe poder
        // distinguir "PIN incorrecto" de "esta persona no puede autorizar esto".
        return new self(422, 'Código de empleado o PIN incorrectos.');
    }

    public static function grantNotUsable(): self
    {
        return new self(422, 'La autorización expiró o ya se utilizó. Solicítala de nuevo.');
    }
}
