<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Requests;

use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de mesa.
 *
 * ## Lo que no cambia: el código y la zona
 *
 * El código porque es como se nombra la mesa en voz alta durante el servicio, y las cuentas ya cobradas lo citan. La
 * zona porque una mesa está físicamente en un sitio: moverla de zona en el sistema sería describir una mudanza que nadie
 * hizo, y para eso está el editor visual de la Iteración 6 —que mueve coordenadas, no pertenencias—.
 */
final class SaveRestaurantTableRequest extends FormRequest
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
        $creando = $this->isMethod('POST');
        $tenantId = app(TenantContext::class)->id();

        return [
            'floor_zone_ulid' => $creando
                ? ['required', 'string', 'size:26', Rule::exists('floor_zones', 'ulid')->where('tenant_id', $tenantId)]
                : ['prohibited'],

            'code' => $creando
                ? ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-]+$/']
                : ['prohibited'],

            'name' => ['sometimes', 'nullable', 'string', 'max:60'],

            // Una mesa de cero asientos no es una mesa. El máximo es alto a propósito: una mesa de banquete de 30
            // existe, y poner un tope bajo obligaría a partirla en varias para poder registrarla.
            'seats' => ['sometimes', 'integer', 'min:1', 'max:99'],

            'shape' => ['sometimes', Rule::in(['rectangle', 'circle'])],

            // Coordenadas lógicas (ADR-003). Se aceptan al editar porque la Iteración 6 las va a mandar, y dejarlas
            // fuera obligaría a tocar este Form Request entonces.
            'x' => ['sometimes', 'numeric', 'min:0', 'max:99999.99'],
            'y' => ['sometimes', 'numeric', 'min:0', 'max:99999.99'],
            'width' => ['sometimes', 'numeric', 'gt:0', 'max:99999.99'],
            'height' => ['sometimes', 'numeric', 'gt:0', 'max:99999.99'],
            'rotation' => ['sometimes', 'numeric', 'min:0', 'max:359.99'],

            // El estado no se elige de una lista: lo mueve lo que pasa con las cuentas de la mesa (§6.3). Una mesa
            // marcada «libre» a mano con una cuenta abierta encima es la peor información posible para quien atiende la
            // puerta.
            'status' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->isMethod('POST')) {
                return;
            }

            $zone = FloorZone::findByUlid($this->string('floor_zone_ulid')->toString());
            $branchId = $zone?->plan?->branch_id;

            if ($branchId === null) {
                return;
            }

            // El código es único en la SUCURSAL, no en la zona: «M1» tiene que ser una sola mesa para quien la nombra
            // en voz alta, y dos zonas con su M1 producirían la peor confusión posible en un servicio.
            $repetido = RestaurantTable::query()
                ->where('branch_id', $branchId)
                ->where('code', $this->string('code')->toString())
                ->exists();

            if ($repetido) {
                $validator->errors()->add('code', 'Ya existe una mesa con ese código en esta sucursal.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'El código de una mesa no se cambia: es como se la nombra durante el servicio.',
            'floor_zone_ulid.prohibited' => 'Una mesa no cambia de zona: está físicamente en un sitio.',
            'status.prohibited' => 'El estado de una mesa lo mueven sus cuentas, no se elige de una lista.',
            'code.regex' => 'El código sólo admite letras, números y guiones.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'floor_zone_ulid' => 'la zona',
            'code' => 'el código',
            'name' => 'el nombre',
            'seats' => 'los asientos',
            'shape' => 'la forma',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }
}
