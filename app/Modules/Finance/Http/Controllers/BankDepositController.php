<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\RegisterBankDeposit;
use App\Modules\Finance\Http\Requests\StoreBankDepositRequest;
use App\Modules\Finance\Http\Resources\BankDepositResource;
use App\Modules\Finance\Infrastructure\Models\BankDeposit;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Depósitos bancarios: la otra mitad del retiro.
 */
final class BankDepositController
{
    use AssertsBranchScope;

    public function __construct(private readonly RegisterBankDeposit $deposits) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, BankDeposit>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: [],
            sortable: ['deposited_on', 'amount'],
            searchable: ['reference', 'bank_name'],
            defaultSort: '-deposited_on',
            dateRanges: ['deposited' => 'deposited_on'],
            handledByCaller: ['branch'],
        );

        $builder = $query->apply(
            BankDeposit::query()->with(['branch', 'createdBy.user', 'createdBy.employeeProfile']),
            $request,
        );

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        return BankDepositResource::collection($builder->paginate($query->perPage($request)));
    }

    public function store(StoreBankDepositRequest $request): JsonResponse
    {
        $validado = $request->validated();

        $sucursal = Branch::query()->where('ulid', $validado['branch_ulid'])->sole();

        // El deposito saca efectivo del negocio. Registrarlo contra una sucursal ajena movería dinero en un libro
        // que quien captura no opera.
        $this->assertBranchInScope((int) $sucursal->id);

        $deposito = $this->deposits->register(
            branch: $sucursal,
            amount: $validado['amount'],
            bankName: $validado['bank_name'],
            reference: $validado['reference'],
            depositedOn: $validado['deposited_on'],
        );

        return (new BankDepositResource(
            $deposito->load(['branch', 'createdBy.user', 'createdBy.employeeProfile'])
        ))->response()->setStatusCode(201);
    }
}
