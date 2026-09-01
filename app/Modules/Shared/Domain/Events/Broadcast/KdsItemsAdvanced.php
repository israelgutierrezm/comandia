<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un platillo del tablero de cocina cambió de estado (KDS — D350).
 *
 * Va al MISMO canal por área que la comanda (`AreaOrderCommanded`): quien lo oye es quien prepara. Así una pantalla de
 * cocina recibe en el mismo sitio lo que llega nuevo y lo que otra pantalla marcó como preparando/listo, y las dos se
 * mantienen al día sin recargar.
 *
 * Lleva sólo el ulid y el estado nuevo de cada línea afectada: la pantalla parcha ese renglón. No lleva dinero, como
 * todo lo del canal de área.
 */
final class KdsItemsAdvanced implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $queue = 'critical';

    /**
     * @param  list<array{ulid: string, status: string, status_label: string}>  $items
     */
    public function __construct(
        public readonly string $tenantUlid,
        public readonly string $branchUlid,
        public readonly string $areaUlid,
        public readonly array $items,
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
        return 'area.items-advanced';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['items' => $this->items];
    }
}
