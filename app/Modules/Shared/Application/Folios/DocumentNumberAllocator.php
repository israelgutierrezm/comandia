<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Folios;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Toma folios consecutivos SIN HUECOS por (tenant, sucursal, tipo, serie)
 * — ARQUITECTURA_MAESTRA §7, D73.
 *
 * ## El mecanismo
 *
 * `SELECT ... FOR UPDATE` sobre la fila de la secuencia, **dentro de la transacción
 * del documento**. El folio y el documento nacen o mueren juntos: si la transacción
 * se revierte, el número vuelve a estar disponible y no queda hueco.
 *
 * ## Por qué exige transacción abierta y falla si no la hay
 *
 * Un `FOR UPDATE` fuera de transacción libera el lock inmediatamente, así que dos
 * peticiones simultáneas leerían el mismo número y producirían un folio duplicado
 * —o un hueco al reintentar—. Llamar a esto sin transacción no es un descuido menor:
 * rompe la única garantía que la clase existe para dar. Por eso lanza excepción en
 * lugar de funcionar "casi siempre".
 *
 * ## Por qué no AUTO_INCREMENT
 *
 * Un autoincremental es global a la tabla y deja huecos en cuanto una transacción
 * se revierte. La foliación tiene que ser por sucursal, por tipo y por serie, y sin
 * huecos.
 *
 * ## Costo aceptado
 *
 * Esto SERIALIZA la creación de documentos por (sucursal, tipo, serie). Es
 * intencional: "sin huecos" es un requisito, no una preferencia. A 500–1,000
 * cuentas al día en el tenant más pesado (ESPECIFICACIÓN_MAESTRA §2) el lock se
 * sostiene sin problema.
 */
final readonly class DocumentNumberAllocator
{
    public function __construct(
        private ConnectionInterface $connection,
        private TenantContext $context,
    ) {}

    /**
     * Reserva y devuelve el siguiente folio de la secuencia.
     *
     * @throws LogicException si se invoca sin una transacción abierta
     */
    public function next(int $branchId, string $documentType, string $series): int
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException(
                'DocumentNumberAllocator::next() exige una transacción abierta. Fuera de '
                .'transacción el lock se libera de inmediato y dos peticiones simultáneas '
                .'tomarían el mismo folio (ARQUITECTURA_MAESTRA §7).'
            );
        }

        $tenantId = $this->context->id();

        $row = $this->connection->table('document_sequences')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('document_type', $documentType)
            ->where('series', $series)
            ->lockForUpdate()
            ->first(['id', 'next_number']);

        if ($row === null) {
            // Primera vez que este tipo de documento se folia en esta sucursal. El
            // índice único de la tabla es la red de seguridad: si dos transacciones
            // llegan aquí a la vez, una falla al insertar y su reintento encuentra la
            // fila ya creada. Se prefiere eso a crear la secuencia por adelantado en
            // el alta de la sucursal, porque los tipos de documento los van
            // introduciendo las iteraciones siguientes.
            $this->connection->table('document_sequences')->insert([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'document_type' => $documentType,
                'series' => $series,
                'next_number' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 1;
        }

        $this->connection->table('document_sequences')
            ->where('id', $row->id)
            ->update([
                'next_number' => $row->next_number + 1,
                'updated_at' => now(),
            ]);

        return (int) $row->next_number;
    }

    /**
     * Folio formateado para presentación: `SERIE-000123`.
     *
     * El relleno a seis dígitos es de presentación, no de dato: en base se guarda el
     * entero, porque ordenar y comparar textos con ceros a la izquierda es una
     * fuente inagotable de errores en reportes.
     */
    public function format(string $series, int $number): string
    {
        return sprintf('%s-%06d', $series, $number);
    }
}
