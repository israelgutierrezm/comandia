<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Application\ManageDeliveryChannel;
use App\Modules\Ecommerce\Http\Requests\SaveDeliveryChannelRequest;
use App\Modules\Ecommerce\Http\Resources\DeliveryChannelResource;
use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Configuración de los canales de marketplace (ADR-015). Superficie de administración, gateada por
 * `module:Ecommerce` y `ecommerce.store.configure` (es configuración de la tienda). Encender/apagar y
 * poner credenciales por sucursal y canal.
 */
final class DeliveryChannelController
{
    use AssertsBranchScope;

    public function __construct(private readonly ManageDeliveryChannel $manage) {}

    public function index(): AnonymousResourceCollection
    {
        return DeliveryChannelResource::collection(
            DeliveryChannelSetting::query()->with('branch')->get(),
        );
    }

    public function upsert(SaveDeliveryChannelRequest $request): JsonResponse
    {
        // El binding por ulid acota al tenant; una sucursal de otro negocio no existe aquí (404).
        $branch = Branch::query()->where('ulid', $request->string('branch_ulid')->toString())->firstOrFail();

        // El tenant_id no basta: una sucursal ajena es del MISMO negocio y llega como modelo válido. Se
        // comprueba que esté en el alcance del rol activo antes de escribir nada.
        $this->assertBranchInScope((int) $branch->id);

        $setting = $this->manage->save(
            (int) $branch->id,
            $request->string('channel')->toString(),
            [
                'is_active' => $request->boolean('is_active'),
                'external_store_id' => $request->input('external_store_id'),
                'commission_rate' => $request->input('commission_rate'),
                'api_key' => $request->input('api_key'),
                'api_secret' => $request->input('api_secret'),
                'webhook_secret' => $request->input('webhook_secret'),
            ],
        );

        return new JsonResponse(['data' => new DeliveryChannelResource($setting->load('branch'))]);
    }
}
