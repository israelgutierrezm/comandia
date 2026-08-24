<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Http\Requests\SaveGatewaySettingRequest;
use App\Modules\Ecommerce\Http\Resources\PaymentGatewaySettingResource;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use Illuminate\Http\JsonResponse;

/**
 * Configuración de la pasarela de pago (Iteración 8, Tanda C, D49). Admin, gateado por `module:Ecommerce` y
 * `ecommerce.gateways.configure` —el permiso más restringido: es el secreto financiero del negocio, fuera hasta del
 * gerente (§10.4)—. Una pasarela activa a la vez. Los secretos se cifran y nunca vuelven por la API.
 */
final class PaymentGatewaySettingController
{
    public function show(): JsonResponse
    {
        $settings = PaymentGatewaySetting::query()->first();

        return new JsonResponse(['data' => $settings === null ? null : new PaymentGatewaySettingResource($settings)]);
    }

    public function update(SaveGatewaySettingRequest $request): JsonResponse
    {
        $settings = PaymentGatewaySetting::query()->first() ?? new PaymentGatewaySetting();

        $settings->active_gateway = $request->input('active_gateway');
        $settings->public_key = $request->input('public_key');

        // Un secreto vacío conserva el guardado (no se puede releer para reenviarlo), como el SMTP de la It.7.
        if ($request->filled('secret_key')) {
            $settings->secret_key = (string) $request->string('secret_key');
        }
        if ($request->filled('webhook_secret')) {
            $settings->webhook_secret = (string) $request->string('webhook_secret');
        }

        $settings->save();

        return new JsonResponse(['data' => new PaymentGatewaySettingResource($settings->refresh())]);
    }
}
