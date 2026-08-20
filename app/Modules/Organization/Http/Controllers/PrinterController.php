<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Domain\Enums\PrinterConnection;
use App\Modules\Organization\Http\Requests\StorePrinterRequest;
use App\Modules\Organization\Http\Requests\UpdatePrinterRequest;
use App\Modules\Organization\Http\Resources\PrinterResource;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Administración de impresoras (§9.1 del diseño de la Iteración 4).
 */
final class PrinterController
{
    use AssertsBranchScope;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Printer>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status', 'connection' => 'connection'],
            sortable: ['name', 'code'],
            searchable: ['name', 'code', 'target'],
            defaultSort: 'name',
            handledByCaller: ['branch'],
        );

        $printers = $query->apply(
            // Se cuentan las asignaciones en la misma consulta: el listado necesita decir «esta imprime las comandas
            // de tres áreas» antes de que alguien la dé de baja, y hacerlo con una consulta por fila sería el problema
            // de N+1 en la pantalla que más filas tiene por sucursal.
            Printer::query()->with('branch')->withCount(['preparationAreas', 'terminals']),
            $request,
        );

        if ($request->filled('branch')) {
            $printers->whereHas('branch', fn ($q) => $q->where('ulid', $request->string('branch')));
        }

        return PrinterResource::collection($printers->paginate($query->perPage($request)));
    }

    /**
     * El catálogo de tipos de conexión, con su etiqueta y qué se espera en el destino.
     *
     * Va por API y no escrito en el cliente por la lección de D139: una etiqueta duplicada en el frontend acaba
     * diciendo algo distinto de lo que el servidor valida, y aquí la pista del destino es justo lo que evita que
     * alguien capture una IP donde va una ruta UNC.
     */
    public function connections(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (PrinterConnection $c): array => [
                'value' => $c->value,
                'label' => $c->label(),
                'target_hint' => $c->targetHint(),
            ], PrinterConnection::cases()),
        ]);
    }

    public function store(StorePrinterRequest $request): JsonResponse
    {
        $sucursalId = Branch::findByUlid($request->string('branch_ulid')->toString())?->id;

        // La sucursal viene del CUERPO. Es configuración y no operación, pero el alcance es el mismo: quien
        // sólo opera una sucursal no equipa otra — y una terminal, impresora o área ajena acaba recibiendo
        // trabajo real.
        $this->assertBranchInScope($sucursalId);

        $printer = Printer::create([
            'branch_id' => $sucursalId,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'connection' => $request->string('connection')->toString(),
            'target' => $request->string('target')->toString(),
            'paper_width' => $request->integer('paper_width', 80),
            'supports_cash_drawer' => $request->boolean('supports_cash_drawer'),
        ]);

        $this->audit->log(
            action: AuditAction::PRINTER_CREATED,
            auditable: $printer,
            after: $printer->only(['code', 'name', 'branch_id', 'connection', 'target', 'paper_width', 'supports_cash_drawer', 'status']),
        );

        // `refresh()` y no la instancia recién creada: el candado de la Iteración 3 (D217) existe por esto —Eloquent
        // devuelve lo asignado y no lo almacenado, así que un valor por omisión de la base no vendría en la respuesta.
        return (new PrinterResource($printer->refresh()->load('branch')->loadCount(['preparationAreas', 'terminals'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Printer $printer): PrinterResource
    {
        return new PrinterResource($printer->load('branch')->loadCount(['preparationAreas', 'terminals']));
    }

    public function update(UpdatePrinterRequest $request, Printer $printer): PrinterResource
    {
        $campos = ['name', 'connection', 'target', 'paper_width', 'supports_cash_drawer'];
        $before = $printer->only($campos);

        $printer->update($request->safe()->all());

        $this->audit->log(
            action: AuditAction::PRINTER_UPDATED,
            auditable: $printer,
            before: $before,
            after: $printer->only($campos),
        );

        return new PrinterResource($printer->refresh()->load('branch')->loadCount(['preparationAreas', 'terminals']));
    }

    /**
     * Baja de impresora: cambio de estado, no borrado (D80).
     *
     * ## No se desasigna de las áreas, y es deliberado
     *
     * Un área que apuntaba a la impresora quemada **sigue apuntando a ella**. Parece descuido y es lo contrario: si al
     * dar de baja se limpiaran las asignaciones, la información de «esta área imprimía aquí» desaparecería justo cuando
     * hace falta para reconfigurar, y quien sustituya la impresora no sabría qué áreas reasignar.
     *
     * Lo que sí ocurre es que el POS deja de crear trabajos hacia ella y lo dice, en lugar de encolar papel que nadie
     * va a imprimir.
     */
    public function archive(Printer $printer): PrinterResource
    {
        $before = ['status' => $printer->status->value];

        $printer->update(['status' => OperationalStatus::Inactive]);

        $this->audit->log(
            action: AuditAction::PRINTER_UPDATED,
            auditable: $printer,
            before: $before,
            after: ['status' => OperationalStatus::Inactive->value],
        );

        return new PrinterResource($printer->refresh()->load('branch')->loadCount(['preparationAreas', 'terminals']));
    }
}
