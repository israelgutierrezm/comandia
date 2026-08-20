<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Finance\Http\Requests\SaveExpenseCategoryRequest;
use App\Modules\Finance\Http\Resources\ExpenseCategoryResource;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Categorías de gasto (§6.5).
 */
final class ExpenseCategoryController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, ExpenseCategory>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['sort_order', 'name'],
            searchable: ['name'],
            defaultSort: 'sort_order',
        );

        return ExpenseCategoryResource::collection(
            $query->apply(ExpenseCategory::query(), $request)->paginate($query->perPage($request))
        );
    }

    public function store(SaveExpenseCategoryRequest $request): JsonResponse
    {
        $category = ExpenseCategory::create([
            'name' => $request->string('name')->toString(),
            'sort_order' => $request->integer('sort_order', 500),
        ]);

        $this->audit->log(
            action: AuditAction::EXPENSE_CATEGORY_CREATED,
            auditable: $category,
            after: $category->only(['name', 'sort_order', 'status']),
        );

        return (new ExpenseCategoryResource($category->refresh()))->response()->setStatusCode(201);
    }

    public function update(SaveExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): ExpenseCategoryResource
    {
        $before = $expenseCategory->only(['name', 'sort_order']);

        // Una categoría del sistema SÍ se renombra, a diferencia de un método de pago: su nombre es una etiqueta de
        // reporte que el negocio ajusta a su vocabulario, no la referencia con la que el diario agrupa el dinero.
        $expenseCategory->update($request->safe()->all());

        $this->audit->log(
            action: AuditAction::EXPENSE_CATEGORY_UPDATED,
            auditable: $expenseCategory,
            before: $before,
            after: $expenseCategory->only(['name', 'sort_order']),
        );

        return new ExpenseCategoryResource($expenseCategory->refresh());
    }

    /**
     * Activar o desactivar.
     *
     * Sin la regla del «último activo» que sí tienen los métodos de pago, y la asimetría es deliberada: un negocio sin
     * métodos de pago no puede cobrar —se detiene la operación—, mientras que uno sin categorías de gasto activas
     * simplemente no puede registrar gastos hasta que active una. Lo segundo es un inconveniente; lo primero es una
     * fila detenida con un cliente esperando.
     */
    public function toggle(ExpenseCategory $expenseCategory): ExpenseCategoryResource
    {
        $activando = ! $expenseCategory->isActive();

        // Lo que sí se protege: dejar una categoría fuera cuando hay gastos que la citan es correcto —el histórico se
        // conserva— pero borrarla no, y de eso se encarga el invariante del modelo.
        if (! $activando && $expenseCategory->is_system && ExpenseCategory::query()->active()->count() <= 1) {
            throw new ConflictHttpException(
                'Es la única categoría de gasto activa: sin ninguna, no se puede registrar un gasto. Activa otra antes '
                .'de dar de baja ésta.'
            );
        }

        $before = ['status' => $expenseCategory->status->value];

        $expenseCategory->update([
            'status' => $activando ? OperationalStatus::Active : OperationalStatus::Inactive,
        ]);

        $this->audit->log(
            action: AuditAction::EXPENSE_CATEGORY_UPDATED,
            auditable: $expenseCategory,
            before: $before,
            after: ['status' => $expenseCategory->refresh()->status->value],
        );

        return new ExpenseCategoryResource($expenseCategory);
    }
}
