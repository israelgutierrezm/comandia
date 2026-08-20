<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Http\Requests\StoreTerminalRequest;
use App\Modules\Organization\Http\Requests\UpdateTerminalRequest;
use App\Modules\Organization\Http\Resources\TerminalResource;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Administración de terminales.
 */
final class TerminalController
{
    use AssertsBranchScope;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Terminal>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['name', 'code', 'last_seen_at'],
            searchable: ['name', 'code'],
            defaultSort: 'name',
        );

        $terminals = $query
            ->apply(Terminal::query()->with(['branch', 'printer']), $request)
            ->paginate($query->perPage($request));

        return TerminalResource::collection($terminals);
    }

    public function store(StoreTerminalRequest $request): JsonResponse
    {
        $sucursalId = Branch::findByUlid($request->string('branch_ulid')->toString())?->id;

        // La sucursal viene del CUERPO. Es configuración y no operación, pero el alcance es el mismo: quien
        // sólo opera una sucursal no equipa otra — y una terminal, impresora o área ajena acaba recibiendo
        // trabajo real.
        $this->assertBranchInScope($sucursalId);

        $terminal = Terminal::create([
            'branch_id' => $sucursalId,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
        ]);

        $this->audit->log(
            action: AuditAction::TERMINAL_CREATED,
            auditable: $terminal,
            after: $terminal->only(['code', 'name', 'branch_id', 'status']),
        );

        return (new TerminalResource($terminal->load(['branch', 'printer'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Terminal $terminal): TerminalResource
    {
        return new TerminalResource($terminal->load(['branch', 'printer']));
    }

    public function update(UpdateTerminalRequest $request, Terminal $terminal): TerminalResource
    {
        $before = $terminal->only(['name', 'printer_id']);

        $data = $request->safe()->except('printer_ulid');

        if ($request->has('printer_ulid')) {
            // Igual que en el área: `null` desasigna y la ausencia no toca nada.
            $ulid = $request->input('printer_ulid');

            $data['printer_id'] = $ulid === null
                ? null
                : Printer::findByUlid((string) $ulid)?->id;
        }

        $terminal->update($data);

        $this->audit->log(
            action: AuditAction::TERMINAL_UPDATED,
            auditable: $terminal,
            before: $before,
            after: $terminal->only(['name', 'printer_id']),
        );

        return new TerminalResource($terminal->refresh()->load(['branch', 'printer']));
    }

    /**
     * Baja de terminal: cambio de estado, no borrado (D80).
     *
     * Una terminal inactiva deja de pasar la validación del header `X-Terminal`, así que el
     * efecto es inmediato en la siguiente petición del POS.
     */
    public function archive(Terminal $terminal): TerminalResource
    {
        $before = ['status' => $terminal->status->value];

        $terminal->update(['status' => OperationalStatus::Inactive]);

        $this->audit->log(
            action: AuditAction::TERMINAL_UPDATED,
            auditable: $terminal,
            before: $before,
            after: ['status' => OperationalStatus::Inactive->value],
        );

        return new TerminalResource($terminal->refresh()->load(['branch', 'printer']));
    }
}
