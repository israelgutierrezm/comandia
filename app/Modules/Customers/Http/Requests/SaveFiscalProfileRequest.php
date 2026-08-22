<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Domain\Sat\SatCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un perfil fiscal (ADR-005, CFDI-ready).
 *
 * ## Validación REAL, no texto libre (ADR-005 regla 1)
 *
 * El RFC se valida por FORMA —12 caracteres persona moral, 13 física— no por su dígito verificador: implementarlo sería
 * una madriguera y un RFC bien formado pero inexistente se descubre al facturar (D200/D266). El régimen y el uso CFDI se
 * validan contra los catálogos oficiales del SAT (`SatCatalog`), y el régimen contra el TIPO DE PERSONA que implica el
 * RFC. La matriz fina régimen↔uso se difiere al timbrado (documentado).
 */
final class SaveFiscalProfileRequest extends FormRequest
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
            'rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/'],
            'business_name' => ['required', 'string', 'max:200'],
            'postal_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'tax_regime_code' => ['required', 'string', Rule::in(array_keys(SatCatalog::taxRegimes()))],
            'cfdi_use_code' => ['required', 'string', Rule::in(array_keys(SatCatalog::cfdiUses()))],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // El RFC en mayúsculas y sin espacios: el catálogo del SAT y el índice único lo esperan normalizado.
        if ($this->has('rfc')) {
            $this->merge(['rfc' => mb_strtoupper(trim((string) $this->input('rfc')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rfc = (string) $this->input('rfc');
            $regime = (string) $this->input('tax_regime_code');

            if ($rfc === '' || $regime === '' || ! SatCatalog::isTaxRegime($regime)) {
                return;
            }

            $personType = SatCatalog::personTypeForRfc($rfc);

            if (! SatCatalog::regimeAllowsPerson($regime, $personType)) {
                $validator->errors()->add(
                    'tax_regime_code',
                    $personType === 'moral'
                        ? 'Ese régimen no aplica a personas morales (RFC de 12 caracteres).'
                        : 'Ese régimen no aplica a personas físicas (RFC de 13 caracteres).',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rfc.regex' => 'Ese RFC no tiene la forma correcta: 12 caracteres para persona moral, 13 para física.',
            'postal_code.regex' => 'El código postal fiscal son 5 dígitos.',
            'tax_regime_code.in' => 'Ese régimen fiscal no está en el catálogo del SAT.',
            'cfdi_use_code.in' => 'Ese uso de CFDI no está en el catálogo del SAT.',
        ];
    }
}
