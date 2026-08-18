<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Shared\Application\Authorization\Authorize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Perfil de empleado, con el PII detrás de su propio permiso (D77).
 *
 * CURP, RFC, NSS y fecha de nacimiento son datos personales sensibles y viven en claro porque
 * el RFC tiene que ser buscable para CFDI y su unicidad verificable por índice. La protección
 * no es el cifrado: es este permiso más la auditoría de lectura.
 *
 * Por eso el Resource decide **por campo** y no el endpoint por respuesta: un gerente puede ver
 * la ficha laboral de su equipo —fechas de alta, puesto— sin ver su CURP. Que la ficha completa
 * requiriera el permiso de PII forzaría a dárselo a quien sólo necesita saber cuándo entró
 * alguien a trabajar.
 */
final class EmployeeProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmployeeProfile $profile */
        $profile = $this->resource;

        $puedeVerSensible = app(Authorize::class)->allows('identity.employee_profiles.view_sensitive');

        return [
            'ulid' => $profile->ulid,

            'legal_name' => [
                'first_name' => $profile->legal_first_name,
                'paternal_surname' => $profile->legal_paternal_surname,
                'maternal_surname' => $profile->legal_maternal_surname,
                'full' => $profile->legalName()->full(),
            ],

            'is_foreigner' => $profile->is_foreigner,
            'hired_at' => $profile->hired_at?->toDateString(),
            'terminated_at' => $profile->terminated_at?->toDateString(),

            // PII: presente sólo con el permiso. `mergeWhen` y no null: la ausencia de la llave
            // le dice al cliente "no tienes permiso", mientras un null diría "no hay dato" —dos
            // cosas distintas que la UI debe poder distinguir para no mostrar "sin CURP" a
            // alguien que simplemente no puede verla.
            //
            // SIN el operador de propagación. `mergeWhen` devuelve un objeto —`MergeValue` con el
            // permiso, `MissingValue` sin él— que el pipeline de recursos aplana al filtrar; intentar
            // desempaquetarlo con `...` lanza «Only arrays and Traversables can be unpacked» y el
            // endpoint respondía **500 siempre**, con permiso y sin él.
            //
            // Estuvo así desde la Iteración 1. Las pruebas cubrían el `DELETE` —que devuelve 204 y no
            // pasa por este recurso— y ni el `GET` ni el `PUT` se habían llamado nunca. Lo encontró el
            // navegador, al abrir la pestaña de perfil laboral de la primera persona.
            $this->mergeWhen($puedeVerSensible, fn (): array => [
                'curp' => $profile->curp,
                'rfc' => $profile->rfc,
                'nss' => $profile->nss,
                'birth_date' => $profile->birth_date?->toDateString(),
            ]),

            'can_view_sensitive' => $puedeVerSensible,
        ];
    }
}
