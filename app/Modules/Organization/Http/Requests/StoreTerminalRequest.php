<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de terminal.
 */
final class StoreTerminalRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
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

            // El único es (tenant, sucursal, código): dos sucursales pueden tener su "Caja 1",
            // y prohibirlo sería una regla inventada.
            $repetido = Terminal::query()
                ->where('branch_id', $branch->id)
                ->where('code', $this->string('code')->toString())
                ->exists();

            if ($repetido) {
                $validator->errors()->add('code', 'Ya existe una terminal con ese código en esta sucursal.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulid.required' => 'Indica a qué sucursal pertenece la terminal.',
            'branch_ulid.exists' => 'La sucursal indicada no existe.',
            'code.regex' => 'El código sólo admite letras, números y guiones.',
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
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }
}
