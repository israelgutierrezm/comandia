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
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Administración de terminales.
 */
final class TerminalController
{
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
            ->apply(Terminal::query()->with('branch'), $request)
            ->paginate($query->perPage($request));

        return TerminalResource::collection($terminals);
    }

    public function store(StoreTerminalRequest $request): JsonResponse
    {
        $terminal = Terminal::create([
            'branch_id' => Branch::findByUlid($request->string('branch_ulid')->toString())?->id,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
        ]);

        $this->audit->log(
            action: AuditAction::TERMINAL_CREATED,
            auditable: $terminal,
            after: $terminal->only(['code', 'name', 'branch_id', 'status']),
        );

        return (new TerminalResource($terminal->load('branch')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Terminal $terminal): TerminalResource
    {
        return new TerminalResource($terminal->load('branch'));
    }

    public function update(UpdateTerminalRequest $request, Terminal $terminal): TerminalResource
    {
        $before = $terminal->only(['name']);

        $terminal->update($request->safe()->all());

        $this->audit->log(
            action: AuditAction::TERMINAL_UPDATED,
            auditable: $terminal,
            before: $before,
            after: $terminal->only(['name']),
        );

        return new TerminalResource($terminal->refresh()->load('branch'));
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

        return new TerminalResource($terminal->refresh()->load('branch'));
    }
}
