<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de método de pago propio del negocio.
 *
 * ## Sólo `custom`, y por eso no se pide la naturaleza
 *
 * Las otras cuatro naturalezas las siembra el sistema y no se crean por API: si un negocio pudiera dar de alta un
 * segundo método de naturaleza `cash`, el arqueo tendría dos fuentes de efectivo esperado y el corte dejaría de poder
 * explicarse. Un vale de despensa, una aplicación de reparto o una tarjeta de regalo son `custom` — y `custom` es «lo
 * define el negocio», no «no se sabe».
 */
final class StorePaymentMethodRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:60'],

            // Las tres banderas son OBLIGATORIAS para un método propio, y no tienen valor por omisión razonable: si el
            // negocio no dice si su vale afecta el cajón, el corte lo va a sumar mal en una dirección u otra. Preguntar
            // es más barato que adivinar.
            'affects_cash_drawer' => ['required', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
            'allows_change' => ['required', 'boolean'],

            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],

            // La naturaleza no se elige: un método creado por API es siempre `custom`.
            'kind' => ['prohibited'],
            'is_system' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $repetido = PaymentMethod::query()
                ->where('code', $this->string('code')->toString())
                ->exists();

            if ($repetido) {
                $validator->errors()->add('code', 'Ya existe un método de pago con ese código.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'El código sólo admite letras, números, guiones y guiones bajos.',
            'kind.prohibited' => 'La naturaleza no se elige: los métodos que da de alta el negocio son de tipo «otro».',
            'is_system.prohibited' => 'Un método del sistema no se crea: los cuatro nacen con el negocio.',
            'affects_cash_drawer.required' => 'Indica si el dinero de este método entra al cajón: de eso depende el arqueo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'el código',
            'name' => 'el nombre',
            'affects_cash_drawer' => 'el efecto en el cajón',
            'requires_reference' => 'la referencia',
            'allows_change' => 'el cambio',
            'sort_order' => 'el orden',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }
}
