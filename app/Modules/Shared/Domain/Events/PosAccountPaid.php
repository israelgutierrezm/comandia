<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una cuenta quedó pagada (§6.3).
 *
 * ## El evento con más oyentes del sistema, y por eso vive en el kernel
 *
 * `Finance` asienta la venta, los pagos, la propina y el cambio en el diario. `Printing` saca el ticket final.
 * `Floor` libera la mesa. E `Inventory` descuenta los insumos **en cola** — el único efecto asíncrono de la iteración
 * (§6.2), porque el POS nunca se bloquea por inventario.
 *
 * Cuatro módulos reaccionando a un hecho del POS. Si el evento viviera en `Pos`, los cuatro declararían depender de un
 * módulo operativo y el grafo se llenaría de flechas hacia arriba (D231). En el kernel, ninguno conoce al POS y el POS
 * no conoce a ninguno.
 *
 * ## NINGÚN oyente puede tumbar el cobro
 *
 * Corre después del commit: el dinero ya entró y la cuenta ya está pagada. Un fallo al imprimir, al asentar o al liberar
 * la mesa se registra y no se propaga — es la lección de D220 aplicada desde el diseño. Deshacer un cobro porque la
 * impresora está apagada sería peor que no imprimir.
 *
 * ## Lleva las LÍNEAS de pago, y eso me lo impuso el candado de fronteras
 *
 * Mi primera versión llevaba sólo los totales, y `Finance` leía `pos_payments` para desglosar por método. Suena bien
 * —«que el desglose salga de la evidencia y no de una copia»— y crea un **ciclo**: `Pos` ya depende de `Finance` desde
 * el paso 6, así que `Finance → Pos` cerraría el círculo y el monolito dejaría de ser modular justo por donde más
 * duele, el dinero.
 *
 * Mirándolo otra vez, el argumento estaba mal planteado. El desglose de pagos **es el hecho**, no una copia del hecho:
 * qué se pagó, con qué método y cuánta propina lleva cada línea es exactamente lo que ocurrió, y va en primitivos como
 * pide D231. La comparación correcta no es con el ticket rendido —eso sí sería duplicar un documento— sino con
 * `PosItemsCancelled`, que ya lleva sus items en el paso 8 por la misma razón.
 */
final readonly class PosAccountPaid implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $accountUlid,
        public string $accountDisplayName,
        public int $posSessionId,
        public string $receiptTicketUlid,

        /** @var numeric-string */
        public string $total,
        /** @var numeric-string */
        public string $paidTotal,
        /** @var numeric-string */
        public string $tipTotal,
        /** @var numeric-string */
        public string $changeTotal,

        /**
         * Las líneas de pago, en primitivos.
         *
         * `payment_method_id` es un id interno y no un ULID, y es la excepción consciente a «nunca ids secuenciales»:
         * esa regla protege lo que se EXPONE por la API, y esto no sale de la aplicación — viaja del POS a Finanzas, y
         * `payment_methods` es una tabla de Finanzas. Mandar el ULID obligaría a una consulta extra por línea para
         * traducirlo a lo que el diario necesita.
         *
         * @var list<array{ulid: string, payment_method_id: int, amount: numeric-string, change_amount: numeric-string, tip_amount: numeric-string, tip_membership_id: int|null, charged_by_membership_id: int}>
         */
        public array $payments,

        /**
         * Los items vendidos, para que `Inventory` descuente los insumos.
         *
         * Viajan en el evento y no se leen de `pos_order_items`, por la misma razón que las líneas de pago: §7.1 del
         * diseño dice que **nadie declara depender de un módulo operativo**, y `Inventory` es un módulo de dominio.
         * Leerlos de aquí obligaría a una flecha hacia arriba que la regla 2 de §2 prohíbe.
         *
         * Incluye las **cortesías**: el plato se preparó y los insumos se gastaron aunque no se cobrara (§6.3). Excluye
         * los cancelados, que no llegaron a la mesa — o si llegaron, ya generaron su merma por su propio camino.
         *
         * @var list<array{item_ulid: string, article_id: int, quantity: numeric-string, preparation_area_id: int|null, is_courtesy: bool}>
         */
        public array $items,

        public int $actorMembershipId,
        public string $paidAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}
