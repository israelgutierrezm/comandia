<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se abrió una caja con su fondo (§6.3).
 *
 * El PRIMER evento que usa el contrato de D231, y por eso conviene decir qué se gana: `Finance` asienta el fondo en el
 * diario sin conocer el módulo `Pos`, y `Pos` no sabe que existe un diario. La flecha de dependencia no existe en
 * ninguna dirección — el contrato vive en el kernel, que no depende de nadie.
 *
 * ## Sólo primitivos, y el monto como cadena
 *
 * El fondo va como cadena decimal y no como `float`: un `float` no representa 1234.56 exactamente, y el asiento del
 * diario es dinero. Es la misma regla que §7 aplica a toda la aritmética monetaria del sistema.
 *
 * ## Síncrono, después del commit
 *
 * Quien abre caja necesita ver el fondo asentado antes de empezar a cobrar. La asincronía de §6.2 es para que el POS no
 * se bloquee por inventario; abrir una caja no es cobrar y no tiene prisa de milisegundos, pero sí de coherencia.
 */
final readonly class PosSessionOpened implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public string $sessionUlid,
        public int $sessionId,
        public int $branchId,
        /** @var numeric-string */
        public string $openingFloat,
        public int $actorMembershipId,
        public string $openedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}
