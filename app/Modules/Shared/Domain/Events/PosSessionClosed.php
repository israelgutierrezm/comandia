<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se cerró una caja (§6.3).
 *
 * ## Lo que este evento NO lleva, y lo que resultó que SÍ tenía que llevar
 *
 * No lleva el esperado ni la diferencia, y eso sigue en pie: el arqueo se **calcula** del diario (§6.5, ADR-004), y
 * adelantarlo aquí obligaría a que el emisor supiera sumar el diario — la responsabilidad que no le toca.
 *
 * Lo que este encabezado afirmaba y era **falso** es que «quien calcula el corte tiene todo lo que necesita». No lo
 * tiene: el esperado sale del diario, pero **lo declarado** son las declaraciones del cajero, que viven en
 * `pos_session_declarations` — una tabla de `Pos`. Y `Finance` no puede leerla sin cerrar un ciclo, porque `Pos` ya
 * depende de él desde el paso 6.
 *
 * Así que las declaraciones viajan aquí, en primitivos. Es lo mismo que ocurrió con las líneas de pago (D255) y con los
 * items vendidos (D271): el hecho completo viaja con el anuncio del hecho.
 *
 * El error se descubrió al escribir el paso 19, que es cuando alguien intentó de verdad calcular una diferencia.
 */
final readonly class PosSessionClosed implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public string $sessionUlid,
        public int $sessionId,
        public int $branchId,
        /**
         * Lo que el cajero declaró al cerrar, por método.
         *
         * `payment_method_id` va como id interno y no como ULID por lo mismo que en `PosAccountPaid`: no sale de la
         * aplicación, y `payment_methods` es una tabla de quien lo recibe.
         *
         * @var list<array{payment_method_id: int, declared_amount: numeric-string}>
         */
        public array $declarations,

        public int $actorMembershipId,
        public string $closedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}
