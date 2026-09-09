<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Canje del secreto de un dispositivo por una sesión de dispositivo (ADR-012).
 *
 * El secreto llega como `{ulid}|{secreto}` —lo entregó el enrolamiento una sola vez y el navegador lo
 * guardó—. No exige autenticación: es lo que ESTABLECE la sesión de dispositivo, igual que `auth/token`
 * crea la credencial de la app. La validación del secreto (existencia, revocación, hash) la hace el
 * controlador; aquí sólo se comprueba que venga algo con forma de secreto.
 */
final class OpenDeviceSessionRequest extends FormRequest
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
            'secret' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'secret.required' => 'Falta el secreto del dispositivo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'secret' => 'el secreto del dispositivo',
        ];
    }
}
