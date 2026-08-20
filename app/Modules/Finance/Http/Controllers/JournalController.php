<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Resources\FinancialMovementResource;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator;

/**
 * Lectura del diario financiero (ADR-004).
 *
 * ## Sólo lectura, y no es una omisión
 *
 * No hay `store`, `update` ni `destroy`. Al diario escriben **únicamente** los oyentes de eventos de dominio: el POS
 * emite `PosAccountPaid` y `Finance` asienta. Un endpoint de escritura sería la puerta por la que el diario deja de ser
 * auditable, porque cualquiera podría asentar un movimiento sin documento que lo respalde.
 *
 * Corregir un asiento se hace con una **reversa enlazada**, y ésa la produce el flujo del documento que se corrige — no
 * una petición al diario.
 */
final class JournalController
{
    /**
     * @return AnonymousResourceCollection<CursorPaginator<int, FinancialMovement>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['type' => 'type'],

            // Sólo por fecha, como el kardex y por la misma razón: cualquier otro orden sobre una tabla que crece con
            // cada cobro sería un `filesort` sobre millones de filas, y los índices terminan en `occurred_at`.
            sortable: ['occurred_at'],

            // Sin búsqueda de texto: lo único textual de un asiento es el nombre de clase de su documento origen, y
            // nadie busca por eso. Declararlo vacío hace que `?search=` se rechace en lugar de ignorarse (D182).
            searchable: [],

            // Del presente al pasado: un diario se lee empezando por lo último que pasó.
            defaultSort: '-occurred_at',
            dateRanges: ['occurred' => 'occurred_at'],
            handledByCaller: ['branch', 'session', 'cash_only'],
        );

        $builder = $query->apply(
            FinancialMovement::query()->with([
                'branch',
                'paymentMethod',
                'actor.user',
                'actor.employeeProfile',
                'reverses',
            ]),
            $request,
        );

        if ($request->filled('branch')) {
            $branch = Branch::query()->where('ulid', $request->string('branch'))->sole();
            $builder->where('branch_id', $branch->id);
        }

        // Por sesión de caja: es la consulta del arqueo, y la que un gerente hace cuando un corte no cuadra. Se filtra
        // por la llave interna porque `pos_sessions` no existe todavía —llega en el paso 6— y hasta entonces no hay
        // ULID de sesión que resolver. Cuando exista, esto pasa a resolverse por ULID como el resto.
        if ($request->filled('session')) {
            $builder->where('pos_session_id', $request->integer('session'));
        }

        // «Sólo lo que mueve el cajón»: la mitad del arqueo. Sin este filtro habría que traer todo el diario y sumar
        // en el cliente, que es donde se cuelan los errores de redondeo (D134).
        if ($request->boolean('cash_only')) {
            $builder->affectingCashDrawer();
        }

        // Cursor y no página numerada: es una tabla de alto volumen y un `OFFSET` grande obliga a MySQL a contar todas
        // las filas anteriores. Misma decisión que el kardex.
        return FinancialMovementResource::collection($builder->cursorPaginate($query->perPage($request)));
    }
}
