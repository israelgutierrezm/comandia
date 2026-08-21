<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una comanda para un área de preparación (§6.3, §6.9).
 *
 * ## Éste SÍ lleva contenido, y por eso el canal es otro
 *
 * A diferencia de `FloorChanged`, la cocina necesita **qué preparar**: sin las líneas, la pantalla tendría que pedir la
 * comanda al recibir el aviso y la cocina vería un parpadeo por cada plato en la hora pico.
 *
 * Lo que hace que eso sea aceptable es que el canal es **por área**, no por sucursal: quien lo oye es quien prepara.
 * Un canal de piso lo escucha todo el que atiende; éste lo escucha la cocina, y lo que lleva es exactamente lo que la
 * cocina ya tendría impreso en papel.
 *
 * **Y aun así no lleva dinero.** Ni precios, ni total. La comanda de papel tampoco los lleva, por la misma razón: a
 * quien cocina no le sirven y a quien pasa por la cocina no le incumben.
 *
 * ## Es un espejo de la impresión, no su sustituto
 *
 * El trabajo de impresión se sigue generando igual. Esta pantalla existe para las cocinas que prefieren monitor a
 * papel, y para ver lo que está en curso sin rebuscar entre tickets — no para reemplazar un mecanismo que funciona sin
 * red.
 */
final class AreaOrderCommanded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $queue = 'critical';

    /**
     * @param  list<array{name: string, quantity: string, notes: string|null}>  $lines
     */
    public function __construct(
        public readonly string $tenantUlid,
        public readonly string $branchUlid,
        public readonly string $areaUlid,
        public readonly string $orderUlid,
        public readonly int $sequence,
        public readonly string $accountName,
        public readonly array $lines,
        public readonly string $commandedAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(
            "tenant.{$this->tenantUlid}.branch.{$this->branchUlid}.area.{$this->areaUlid}"
        )];
    }

    public function broadcastAs(): string
    {
        return 'area.order-commanded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_ulid' => $this->orderUlid,
            'sequence' => $this->sequence,
            'account_name' => $this->accountName,
            'lines' => $this->lines,
            'commanded_at' => $this->commandedAt,
        ];
    }
}
