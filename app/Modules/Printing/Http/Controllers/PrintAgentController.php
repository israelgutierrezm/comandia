<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Printing\Http\Requests\StorePrintAgentRequest;
use App\Modules\Printing\Http\Resources\PrintAgentResource;
use App\Modules\Printing\Infrastructure\Models\PrintAgent;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Alta y rotación de agentes de impresión.
 *
 * ## El token se muestra UNA vez
 *
 * Al alta y al rotarlo. La base guarda el hash, así que no hay forma de volver a verlo — si se pierde, se rota. Es la
 * misma disciplina de un PIN o una contraseña, y por la misma razón: un volcado de la base no debe entregar acceso a
 * las impresoras de todos los negocios.
 *
 * La incomodidad es real —quien lo copió mal tiene que rotar— y es preferible a la alternativa, que sería poder leerlo
 * de la pantalla en cualquier momento.
 */
final class PrintAgentController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PrintAgent>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['name', 'last_seen_at'],
            searchable: ['name'],
            defaultSort: 'name',
            handledByCaller: ['branch'],
        );

        $builder = $query->apply(PrintAgent::query()->with('branch'), $request);

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        return PrintAgentResource::collection($builder->paginate($query->perPage($request)));
    }

    public function store(StorePrintAgentRequest $request): JsonResponse
    {
        $branch = Branch::query()->where('ulid', $request->string('branch_ulid'))->sole();

        [$agent, $token] = $this->create($branch->id, (string) $request->string('name'));

        $this->audit->log(
            action: AuditAction::PRINT_AGENT_CREATED,
            auditable: $agent,
            after: ['name' => $agent->name, 'branch' => $branch->name],
        );

        return $this->withToken($agent, $token, 201);
    }

    /**
     * Rota el token.
     *
     * El anterior deja de servir en el momento. Es lo que se hace cuando una computadora de cocina se retira, se pierde
     * o alguien sospecha que el token salió de ahí — y tiene que ser inmediato, porque el valor de rotar es cortar el
     * acceso ya.
     */
    public function rotate(PrintAgent $printAgent): JsonResponse
    {
        $token = $this->issue($printAgent);

        $this->audit->log(
            action: AuditAction::PRINT_AGENT_TOKEN_ROTATED,
            auditable: $printAgent,
            after: ['name' => $printAgent->name],
        );

        return $this->withToken($printAgent->refresh(), $token, 200);
    }

    public function archive(PrintAgent $printAgent): PrintAgentResource
    {
        $printAgent->update(['status' => 'inactive']);

        $this->audit->log(
            action: AuditAction::PRINT_AGENT_ARCHIVED,
            auditable: $printAgent,
            after: ['name' => $printAgent->name],
        );

        return new PrintAgentResource($printAgent->refresh()->load('branch'));
    }

    /**
     * @return array{0: PrintAgent, 1: string}
     */
    private function create(int $branchId, string $name): array
    {
        $agent = PrintAgent::create([
            'branch_id' => $branchId,
            'name' => $name,
            'token_hash' => 'pendiente',
        ]);

        return [$agent, $this->issue($agent)];
    }

    /**
     * Genera un token nuevo y guarda su hash.
     *
     * 40 caracteres aleatorios: es un secreto que vive en un archivo de configuración de una computadora, no algo que
     * alguien teclee. Se hashea con SHA-256 sin sal a propósito —ver el middleware—: hace falta poder buscarlo por
     * índice en cada sondeo, y la entropía del token es la que hace el trabajo que en una contraseña hace la sal.
     */
    private function issue(PrintAgent $agent): string
    {
        $token = Str::random(40);

        $agent->update(['token_hash' => hash('sha256', $token)]);

        return $token;
    }

    private function withToken(PrintAgent $agent, string $token, int $status): JsonResponse
    {
        $datos = (new PrintAgentResource($agent->load('branch')))->resolve();

        // El token va FUERA del recurso, y no es un detalle de forma: el recurso se usa también en la lista, y una llave
        // que sólo existe a veces es como un secreto acaba publicándose por accidente.
        $datos['token'] = $token;
        $datos['token_notice'] = 'Cópialo ahora: no se puede volver a ver. Si se pierde, rota el token del agente.';

        return new JsonResponse(['data' => $datos], $status);
    }
}
