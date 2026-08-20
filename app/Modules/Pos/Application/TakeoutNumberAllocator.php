<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * El siguiente número de mostrador de la jornada.
 *
 * ## Exige transacción abierta, como el asignador de folios
 *
 * Fuera de transacción el lock se libera de inmediato y dos peticiones simultáneas se llevarían el mismo número. Es el
 * mismo error que `DocumentNumberAllocator` cierra con la misma comprobación, y por eso ésta también revienta en lugar
 * de abrir una transacción por su cuenta: quien asigna un número está creando una cuenta, y el número tiene que vivir o
 * morir con ella.
 *
 * ## La jornada la fija la ZONA HORARIA DE LA SUCURSAL
 *
 * La jornada de un restaurante no termina a medianoche UTC. Un pedido de la 1:30 de la madrugada en Ciudad de México
 * pertenece al día anterior para quien opera, y a la fecha siguiente si se calcula en UTC — que es como el mostrador
 * acabaría gritando el número 1 a media noche con quince pedidos activos.
 *
 * Los timestamps se guardan en UTC (§7) y la presentación usa la zona de la sucursal; esto es un caso de presentación
 * aunque parezca un dato, porque «qué jornada es» es una pregunta sobre el negocio y no sobre el reloj del servidor.
 *
 * ## Y NO cierra a las 4 de la madrugada ni pregunta por la caja
 *
 * Sería más fino atar la jornada al turno de caja abierto —lo que la mayoría de los sistemas hacen— y aquí no se puede:
 * un pedido para llevar puede tomarse sin caja abierta, igual que una cuenta (D252). Atarlo al turno haría que el
 * mostrador dejara de numerar cuando el cajero sale a comer.
 *
 * La contrapartida honesta: un negocio que cierra a las 3 de la madrugada verá el contador reiniciarse a medianoche, con
 * pedidos activos del «día anterior» conviviendo con los números nuevos. Cuando aparezca, se resuelve con un ajuste de
 * hora de corte de jornada — una llave de configuración, no un rediseño.
 */
final readonly class TakeoutNumberAllocator
{
    public function __construct(
        private ConnectionInterface $connection,
        private TenantContext $context,
    ) {}

    public function next(Branch $branch): int
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException(
                'TakeoutNumberAllocator::next() exige una transacción abierta. Fuera de transacción el lock se libera '
                .'de inmediato y dos pedidos simultáneos se llevarían el mismo número de mostrador.'
            );
        }

        $tenantId = $this->context->id();
        $jornada = $this->businessDate($branch);

        $fila = $this->connection->table('pos_takeout_counters')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branch->id)
            ->where('business_date', $jornada)
            ->lockForUpdate()
            ->first();

        if ($fila === null) {
            // El primer pedido del día. Se inserta con el 1 ya puesto y se atrapa el choque: dos peticiones simultáneas
            // llegan aquí las dos —ninguna encontró fila que bloquear— y el único de (negocio, sucursal, jornada) hace
            // que la segunda falle. Entonces vuelve a intentarlo, y esta vez sí hay fila que bloquear.
            try {
                $this->connection->table('pos_takeout_counters')->insert([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branch->id,
                    'business_date' => $jornada,
                    'last_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $fila = $this->connection->table('pos_takeout_counters')
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $branch->id)
                    ->where('business_date', $jornada)
                    ->lockForUpdate()
                    ->first();
            }
        }

        $siguiente = (int) $fila->last_number + 1;

        $this->connection->table('pos_takeout_counters')
            ->where('id', $fila->id)
            ->update(['last_number' => $siguiente, 'updated_at' => now()]);

        return $siguiente;
    }

    /**
     * La jornada de la sucursal, en su zona horaria.
     */
    private function businessDate(Branch $branch): string
    {
        return now()->setTimezone((string) $branch->timezone)->toDateString();
    }
}
