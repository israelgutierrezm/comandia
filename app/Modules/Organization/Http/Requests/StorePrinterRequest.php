<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Domain\Enums\PrinterConnection;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de impresora.
 */
final class StorePrinterRequest extends FormRequest
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
            'branch_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('branches', 'ulid')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:60'],

            'connection' => ['required', Rule::enum(PrinterConnection::class)],
            'target' => ['required', 'string', 'max:120'],

            // Los dos anchos de rollo térmico del mercado. Un `in` y no un rango: no existe una impresora de 63 mm, y
            // aceptar cualquier número dejaría entrar un dedazo que después rompe el formato del ticket sin explicación.
            'paper_width' => ['sometimes', 'required', 'integer', 'in:58,80'],

            'supports_cash_drawer' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branch = Branch::findByUlid($this->string('branch_ulid')->toString());

            if ($branch === null) {
                return;
            }

            // El único es (tenant, sucursal, código): dos sucursales pueden tener su «COCINA», y prohibirlo sería una
            // regla inventada. Misma decisión que en terminales y almacenes.
            $repetido = Printer::query()
                ->where('branch_id', $branch->id)
                ->where('code', $this->string('code')->toString())
                ->exists();

            if ($repetido) {
                $validator->errors()->add('code', 'Ya existe una impresora con ese código en esta sucursal.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulid.required' => 'Indica a qué sucursal pertenece la impresora.',
            'branch_ulid.exists' => 'La sucursal indicada no existe.',
            'code.regex' => 'El código sólo admite letras, números y guiones.',
            'paper_width.in' => 'El ancho de papel sólo puede ser 58 u 80 milímetros.',
            'target.required' => 'Indica cómo llegar a la impresora: su IP, su nombre de dispositivo o su ruta compartida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_ulid' => 'la sucursal',
            'code' => 'el código',
            'name' => 'el nombre',
            'connection' => 'el tipo de conexión',
            'target' => 'el destino',
            'paper_width' => 'el ancho de papel',
            'supports_cash_drawer' => 'el cajón de dinero',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }
}
