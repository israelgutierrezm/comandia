<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edita un tablero: lo renombra, lo publica a un rol o lo despublica (`published_role_ulid: null`).
 *
 * ## Las mismas reglas que el alta
 *
 * El nombre lleva el tope de la columna (80), igual que en `StoreDashboardRequest`. Los dos campos son `sometimes`
 * porque renombrar y publicar viajan por separado —la pantalla manda sólo el suyo—, pero si un campo VIENE tiene que ser
 * válido: un nombre vacío es un error, no un «no cambió nada» que deja a la pantalla dando por guardado lo que nunca se
 * guardó.
 *
 * ## Un rol que no existe NO despublica
 *
 * `null` es la única forma de despublicar. Un ULID que no es un rol de ESTE negocio —borrado después de cargar la lista,
 * o de otro negocio— es un error de quien llama; antes se traducía a `null` y dejaba el tablero sin publicar con un 200.
 *
 * Validar la forma no concede nada: el permiso de publicar (`dashboards.dashboards.publish`) lo sigue exigiendo el
 * controlador en cuanto el cuerpo trae `published_role_ulid`, y editar sigue acotado al autor.
 */
final class UpdateDashboardRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:80'],

            'published_role_ulid' => [
                'sometimes', 'nullable', 'string', 'size:26',
                Rule::exists('roles', 'ulid')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'published_role_ulid.exists' => 'El rol elegido ya no existe en el negocio. El tablero se queda como estaba.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'published_role_ulid' => 'el rol',
        ];
    }
}
