<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Promotions\Http\Requests\SavePromotionRequest;
use App\Modules\Promotions\Http\Resources\PromotionResource;
use App\Modules\Promotions\Infrastructure\Models\Promotion;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Las DEFINICIONES de promoción (§6.3). El motor que las APLICA vive en `Application/PromotionEngine`; este controlador
 * sólo las administra.
 *
 * Los objetivos y las sucursales se guardan por ULID de entrada y se resuelven a la llave interna aquí: la API nunca
 * expone ni acepta ids secuenciales (§7).
 */
final class PromotionController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContextHolder $context,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Promotion>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status', 'type' => 'type'],
            sortable: ['name', 'priority', 'created_at'],
            searchable: ['name'],
            defaultSort: 'name',
        );

        $builder = $query->apply(Promotion::query()->with(['targets.article:id,ulid', 'targets.category:id,ulid', 'branches.branch:id,ulid']), $request);

        return PromotionResource::collection($builder->paginate($query->perPage($request)));
    }

    public function show(Promotion $promotion): PromotionResource
    {
        return new PromotionResource($promotion->load(['targets.article:id,ulid', 'targets.category:id,ulid', 'branches.branch:id,ulid']));
    }

    public function store(SavePromotionRequest $request): JsonResponse
    {
        $actor = (int) $this->context->get()->requireMembership()->id;

        $promotion = DB::transaction(function () use ($request, $actor): Promotion {
            $promotion = Promotion::create([
                ...$this->attributesFrom($request),
                'created_by_membership_id' => $actor,
            ]);

            $this->syncTargets($promotion, $request);
            $this->syncBranches($promotion, $request);

            return $promotion;
        });

        $this->audit->log(
            action: AuditAction::PROMOTION_CREATED,
            auditable: $promotion,
            after: ['name' => $promotion->name, 'type' => $promotion->type->value],
        );

        return (new PromotionResource($promotion->refresh()->load(['targets.article:id,ulid', 'targets.category:id,ulid', 'branches.branch:id,ulid'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(SavePromotionRequest $request, Promotion $promotion): PromotionResource
    {
        // Bloqueo optimista: dos personas editando el catálogo no se pisan en silencio (el mismo mecanismo de floor_plans).
        if ((int) $request->integer('version') !== (int) $promotion->version) {
            throw new ConflictHttpException(
                'Esta promoción cambió mientras la editabas. Vuelve a cargarla antes de guardar.'
            );
        }

        $antes = $promotion->only(['name', 'type', 'status', 'priority']);

        DB::transaction(function () use ($request, $promotion): void {
            $promotion->update($this->attributesFrom($request));

            // `version` NO está en `$fillable` a propósito: es un mecanismo de concurrencia, no un campo del negocio, y
            // si fuera asignable en masa el cliente podría fijar la versión que quisiera. Se sube por asignación directa,
            // el mismo criterio que `pos_accounts.version`.
            $promotion->version = (int) $promotion->version + 1;
            $promotion->save();

            $this->syncTargets($promotion, $request);
            $this->syncBranches($promotion, $request);
        });

        $this->audit->log(
            action: AuditAction::PROMOTION_UPDATED,
            auditable: $promotion,
            before: $antes,
            after: $promotion->only(['name', 'type', 'status', 'priority']),
        );

        return new PromotionResource($promotion->refresh()->load(['targets.article:id,ulid', 'targets.category:id,ulid', 'branches.branch:id,ulid']));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFrom(SavePromotionRequest $request): array
    {
        $type = (string) $request->string('type');

        return [
            'name' => (string) $request->string('name'),
            'type' => $type,

            // Sólo la columna del tipo elegido lleva valor; las demás se limpian, para que cambiar de tipo no arrastre
            // el valor del anterior.
            'percent_value' => $type === 'percentage' ? $request->string('percent_value')->toString() : null,
            'amount_value' => in_array($type, ['amount', 'special_price'], true) ? $request->string('amount_value')->toString() : null,
            'buy_quantity' => $type === 'nxm' ? $request->integer('buy_quantity') : null,
            'pay_quantity' => $type === 'nxm' ? $request->integer('pay_quantity') : null,

            'starts_on' => $request->date('starts_on')?->toDateString(),
            'ends_on' => $request->date('ends_on')?->toDateString(),
            'daily_start' => $request->input('daily_start'),
            'daily_end' => $request->input('daily_end'),
            'weekday_mask' => $this->weekdayMask($request),

            'all_branches' => $request->boolean('all_branches'),
            'priority' => $request->integer('priority', 0),
            'is_stackable' => $request->boolean('is_stackable'),
            'status' => (string) ($request->string('status')->toString() ?: 'active'),
        ];
    }

    /**
     * Lista de días (0..6) a máscara de bits. Vacío o ausente = todos los días (127).
     */
    private function weekdayMask(SavePromotionRequest $request): int
    {
        /** @var list<int> $weekdays */
        $weekdays = $request->input('weekdays', []);

        if ($weekdays === []) {
            return 127;
        }

        $mask = 0;

        foreach ($weekdays as $day) {
            $mask |= 1 << (int) $day;
        }

        return $mask;
    }

    private function syncTargets(Promotion $promotion, SavePromotionRequest $request): void
    {
        // Resync completo: borrar e insertar. Una promoción tiene pocos objetivos y su edición es un acto de
        // configuración, no una operación caliente; el diff fino no compensaría la complejidad.
        $promotion->targets()->delete();

        /** @var list<array{article_ulid?: string|null, category_ulid?: string|null}> $targets */
        $targets = $request->input('targets', []);

        foreach ($targets as $target) {
            $articleId = isset($target['article_ulid'])
                ? Article::findByUlid((string) $target['article_ulid'])?->id
                : null;

            $categoryId = isset($target['category_ulid'])
                ? ArticleCategory::findByUlid((string) $target['category_ulid'])?->id
                : null;

            $promotion->targets()->create([
                'article_id' => $articleId,
                'article_category_id' => $categoryId,
            ]);
        }
    }

    private function syncBranches(Promotion $promotion, SavePromotionRequest $request): void
    {
        $promotion->branches()->delete();

        if ($request->boolean('all_branches')) {
            return;
        }

        /** @var list<string> $ulids */
        $ulids = $request->input('branch_ulids', []);

        foreach ($ulids as $ulid) {
            $branchId = Branch::findByUlid((string) $ulid)?->id;

            if ($branchId !== null) {
                $promotion->branches()->create(['branch_id' => $branchId]);
            }
        }
    }
}
