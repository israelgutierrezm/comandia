<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envía un correo de prueba a una dirección, para validar la configuración SMTP del negocio (Tanda D1).
 */
final class SendTestMailRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:191'],
        ];
    }
}
