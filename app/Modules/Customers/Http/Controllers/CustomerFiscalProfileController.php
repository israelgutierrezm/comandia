<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Domain\Sat\SatCatalog;
use App\Modules\Customers\Http\Requests\SaveFiscalProfileRequest;
use App\Modules\Customers\Http\Resources\CustomerFiscalProfileResource;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerFiscalProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Los perfiles fiscales de un cliente (ADR-005). Anidados bajo el cliente: un perfil fiscal no existe sin su cliente.
 */
final class CustomerFiscalProfileController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<int, CustomerFiscalProfile>
     */
    public function index(Customer $customer): AnonymousResourceCollection
    {
        return CustomerFiscalProfileResource::collection(
            $customer->fiscalProfiles()->orderByDesc('is_default')->orderBy('business_name')->get(),
        );
    }

    public function store(SaveFiscalProfileRequest $request, Customer $customer): JsonResponse
    {
        $profile = DB::transaction(function () use ($request, $customer): CustomerFiscalProfile {
            $this->clearDefaultIfNeeded($request, $customer);

            return $customer->fiscalProfiles()->create([
                ...$request->safe()->except(['is_default']),
                'person_type' => SatCatalog::personTypeForRfc((string) $request->string('rfc')),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        $this->audit->log(
            action: AuditAction::CUSTOMER_FISCAL_PROFILE_SAVED,
            auditable: $profile,
            after: ['customer' => $customer->name, 'rfc' => $profile->rfc],
        );

        return (new CustomerFiscalProfileResource($profile))->response()->setStatusCode(201);
    }

    public function update(SaveFiscalProfileRequest $request, Customer $customer, CustomerFiscalProfile $fiscalProfile): CustomerFiscalProfileResource
    {
        $this->assertBelongs($customer, $fiscalProfile);

        DB::transaction(function () use ($request, $customer, $fiscalProfile): void {
            $this->clearDefaultIfNeeded($request, $customer, $fiscalProfile->id);

            $fiscalProfile->update([
                ...$request->safe()->except(['is_default']),
                'person_type' => SatCatalog::personTypeForRfc((string) $request->string('rfc')),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        $this->audit->log(
            action: AuditAction::CUSTOMER_FISCAL_PROFILE_SAVED,
            auditable: $fiscalProfile,
            after: ['customer' => $customer->name, 'rfc' => $fiscalProfile->rfc],
        );

        return new CustomerFiscalProfileResource($fiscalProfile->refresh());
    }

    public function destroy(Customer $customer, CustomerFiscalProfile $fiscalProfile): JsonResponse
    {
        $this->assertBelongs($customer, $fiscalProfile);

        $this->audit->log(
            action: AuditAction::CUSTOMER_FISCAL_PROFILE_DELETED,
            auditable: $fiscalProfile,
            before: ['customer' => $customer->name, 'rfc' => $fiscalProfile->rfc],
        );

        $fiscalProfile->delete();

        return new JsonResponse(null, 204);
    }

    /**
     * Quita el «predeterminado» de los demás perfiles del cliente antes de marcar uno nuevo, para no chocar con el
     * centinela único (una fila predeterminada por cliente).
     */
    private function clearDefaultIfNeeded(SaveFiscalProfileRequest $request, Customer $customer, ?int $except = null): void
    {
        if (! $request->boolean('is_default')) {
            return;
        }

        $customer->fiscalProfiles()
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function assertBelongs(Customer $customer, CustomerFiscalProfile $profile): void
    {
        abort_unless((int) $profile->customer_id === (int) $customer->id, 404);
    }
}
