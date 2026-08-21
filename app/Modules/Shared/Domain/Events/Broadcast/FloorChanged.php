<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * «Algo cambió en este piso.»
 *
 * ## Un solo tipo de evento en el canal del piso, a propósito
 *
 * La pantalla del piso se pinta con **una** petición que trae plano, mesas y cuentas. Cuando algo cambia, lo que hace
 * es volver a pedirla. Así que el canal no necesita describir el cambio con precisión: necesita **avisar** de que lo
 * hubo, y dar una pista suficiente para que la pantalla decida si le interesa.
 *
 * Con un evento por hecho —cuenta abierta, cuenta cobrada, comanda enviada, mesa liberada— habría cuatro contratos que
 * mantener sincronizados con una pantalla que de todas formas recarga entera. Cuatro formas de decir lo mismo son
 * cuatro sitios donde una puede quedarse atrás.
 *
 * ## Los eventos de difusión NO son los eventos de dominio
 *
 * `PosOrderCommanded` lleva las líneas de la comanda; `PosAccountPaid` lleva los importes. Difundirlos tal cual
 * publicaría todo eso en un canal que oye **todo el que atiende**, incluidos roles que no pueden ver dinero. Este
 * evento es la traducción, y la capa extra es justo lo que impide que mañana alguien añada un campo al evento de
 * dominio y lo publique sin darse cuenta.
 *
 * Por eso lo que viaja es el mínimo: qué mesa, en qué estado quedó, qué cuenta hay encima y por qué se avisa. Ni
 * totales, ni líneas, ni nombres de clientes. Quien necesite más lo pide por la API, donde su permiso sí se comprueba.
 *
 * ## Va por COLA, y eso es una decisión de seguridad operativa
 *
 * `ShouldBroadcast` y no `ShouldBroadcastNow`: emitir dentro de la petición haría que el cobro esperara a una llamada
 * al servidor de WebSockets, y **un Reverb caído tumbaría el cobro**. Es exactamente lo que D220 prohíbe — un efecto
 * posterior al commit no puede tumbar la operación. Con cola, un Reverb caído retrasa el pintado del piso y nada más.
 *
 * El costo es que en desarrollo, sin `queue:work`, esto no llega nunca. Lo cubre el respaldo de sondeo, que es
 * obligatorio por §6.9 y aquí además es lo que hace que una máquina de desarrollo no parezca rota.
 */
final class FloorChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** Cola `critical`: el piso es operación en curso, no un informe que puede esperar detrás de una exportación. */
    public string $queue = 'critical';

    public function __construct(
        public readonly string $tenantUlid,
        public readonly string $branchUlid,
        public readonly ?string $tableUlid,
        public readonly ?string $tableStatus,
        public readonly ?string $accountUlid,

        /** `table_state` o `order_commanded`. La pantalla decide si le interesa sin tener que adivinar. */
        public readonly string $reason,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // El canal lleva el tenant Y la sucursal. Sin el tenant, dos negocios con la misma sucursal número uno
        // compartirían canal — y el aislamiento que ADR-002 impone en la base se perdería en el aire.
        return [new PrivateChannel("tenant.{$this->tenantUlid}.branch.{$this->branchUlid}.floor")];
    }

    public function broadcastAs(): string
    {
        return 'floor.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'table_ulid' => $this->tableUlid,
            'table_status' => $this->tableStatus,
            'account_ulid' => $this->accountUlid,
            'reason' => $this->reason,
        ];
    }
}
