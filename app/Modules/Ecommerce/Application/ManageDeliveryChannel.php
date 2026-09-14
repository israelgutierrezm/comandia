<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;

/**
 * Alta/edición de la configuración de un canal de marketplace por sucursal (ADR-015): encender/apagar,
 * paquetería y credenciales. Un upsert por (sucursal, canal).
 *
 * Los secretos son **preservantes**: sólo se sobrescriben si vienen en la petición. Como se guardan
 * cifrados y no se re-leen para mostrarse, un guardado sin secreto conserva el que ya estaba —igual que
 * `PaymentGatewaySettingController`—.
 */
final class ManageDeliveryChannel
{
    /**
     * @param  array{is_active: bool, external_store_id?: ?string, commission_rate?: string|int|float|null, api_key?: ?string, api_secret?: ?string, webhook_secret?: ?string}  $data
     */
    public function save(int $branchId, string $channel, array $data): DeliveryChannelSetting
    {
        $setting = DeliveryChannelSetting::query()
            ->where('branch_id', $branchId)
            ->where('channel', $channel)
            ->first()
            ?? new DeliveryChannelSetting(['branch_id' => $branchId, 'channel' => $channel]);

        $setting->is_active = $data['is_active'];
        $setting->external_store_id = $data['external_store_id'] ?? null;
        $setting->commission_rate = (string) ($data['commission_rate'] ?? '0.00');

        // Secreto vacío = conservar el guardado (no se puede re-leer para reenviarlo).
        foreach (['api_key', 'api_secret', 'webhook_secret'] as $secret) {
            if (! empty($data[$secret])) {
                $setting->{$secret} = $data[$secret];
            }
        }

        $setting->save();

        return $setting;
    }
}
