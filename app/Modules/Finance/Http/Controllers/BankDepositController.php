<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\RegisterBankDeposit;
use App\Modules\Finance\Http\Resources\BankDepositResource;
use App\Modules\Finance\Infrastructure\Models\BankDeposit;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Depósitos bancarios: la otra mitad del retiro.
 */
final class BankDepositController
{
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

    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'branch_ulid' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'bank_name' => ['required', 'string', 'min:2', 'max:60'],

            // El folio del comprobante, obligatorio: sin él no se puede buscar en el estado de cuenta, que es lo único
            // para lo que sirve registrarlo.
            'reference' => ['required', 'string', 'min:1', 'max:60'],

            // No puede ser futura: un depósito que todavía no ocurrió no es un depósito.
            'deposited_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $deposito = $this->deposits->register(
            branch: Branch::query()->where('ulid', $validado['branch_ulid'])->sole(),
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
