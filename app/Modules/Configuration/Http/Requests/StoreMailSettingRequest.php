<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guarda la configuración de correo del negocio (Tanda D1).
 *
 * La contraseña es OPCIONAL al editar: si se deja vacía, se conserva la guardada (para no obligar a re-teclearla al
 * cambiar el host). En el primer alta es obligatoria, y eso lo verifica el controlador.
 */
final class StoreMailSettingRequest extends FormRequest
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
            'host' => ['required', 'string', 'max:191'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['required', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:191'],
            'from_address' => ['required', 'email', 'max:191'],
            'from_name' => ['required', 'string', 'max:120'],
        ];
    }
}
