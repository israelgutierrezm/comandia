<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Http\Requests\SaveAddressRequest;
use App\Modules\Customers\Http\Resources\CustomerAddressResource;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Las direcciones de un cliente (§6.6). Anidadas bajo el cliente.
 */
final class CustomerAddressController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<int, CustomerAddress>
     */
    public function index(Customer $customer): AnonymousResourceCollection
    {
        return CustomerAddressResource::collection(
            $customer->addresses()->orderByDesc('is_default')->orderBy('label')->get(),
        );
    }

    public function store(SaveAddressRequest $request, Customer $customer): JsonResponse
    {
        $address = DB::transaction(function () use ($request, $customer): CustomerAddress {
            $this->clearDefaultIfNeeded($request, $customer);

            return $customer->addresses()->create([
                ...$request->safe()->except(['is_default']),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        $this->audit->log(
            action: AuditAction::CUSTOMER_ADDRESS_SAVED,
            auditable: $address,
            after: ['customer' => $customer->name, 'label' => $address->label],
        );

        return (new CustomerAddressResource($address))->response()->setStatusCode(201);
    }

    public function update(SaveAddressRequest $request, Customer $customer, CustomerAddress $address): CustomerAddressResource
    {
        $this->assertBelongs($customer, $address);

        DB::transaction(function () use ($request, $customer, $address): void {
            $this->clearDefaultIfNeeded($request, $customer, $address->id);

            $address->update([
                ...$request->safe()->except(['is_default']),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        $this->audit->log(
            action: AuditAction::CUSTOMER_ADDRESS_SAVED,
            auditable: $address,
            after: ['customer' => $customer->name, 'label' => $address->label],
        );

        return new CustomerAddressResource($address->refresh());
    }

    public function destroy(Customer $customer, CustomerAddress $address): JsonResponse
    {
        $this->assertBelongs($customer, $address);

        $this->audit->log(
            action: AuditAction::CUSTOMER_ADDRESS_DELETED,
            auditable: $address,
            before: ['customer' => $customer->name, 'label' => $address->label],
        );

        $address->delete();

        return new JsonResponse(null, 204);
    }

    private function clearDefaultIfNeeded(SaveAddressRequest $request, Customer $customer, ?int $except = null): void
    {
        if (! $request->boolean('is_default')) {
            return;
        }

        $customer->addresses()
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function assertBelongs(Customer $customer, CustomerAddress $address): void
    {
        abort_unless((int) $address->customer_id === (int) $customer->id, 404);
    }
}
