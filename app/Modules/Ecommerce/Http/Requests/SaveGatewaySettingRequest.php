<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Configura la pasarela activa y sus credenciales. Los secretos vacíos NO borran los guardados (se conservan), como la
 * configuración de correo de la It.7.
 */
final class SaveGatewaySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'active_gateway' => ['nullable', 'in:fake,mercadopago,stripe'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:500'],
        ];
    }
}
