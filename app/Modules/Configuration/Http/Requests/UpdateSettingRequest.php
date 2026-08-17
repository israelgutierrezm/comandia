<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Requests;

use App\Modules\Configuration\Domain\Enums\SettingType;
use App\Modules\Configuration\Domain\SettingCatalog;
use App\Modules\Configuration\Domain\SettingDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Escritura de una llave de configuración.
 *
 * Las reglas se derivan de la DEFINICIÓN del catálogo, no se escriben aquí: el catálogo ya
 * declara el tipo y los valores permitidos, y duplicarlo en un Form Request garantizaría que
 * tarde o temprano las dos versiones discrepen.
 *
 * Esto también hace que agregar una llave nueva no exija tocar validación: se declara en el
 * catálogo y queda validada.
 */
final class UpdateSettingRequest extends FormRequest
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
        $definition = $this->definition();

        return [
            'value' => ['required', ...$this->rulesForType($definition)],
        ];
    }

    /**
     * La definición de la llave pedida.
     *
     * Una llave inexistente es 404 y no 422: el recurso `settings/{key}` no existe, y decir
     * "dato inválido" sugeriría que la llave es correcta y el valor no.
     */
    public function definition(): SettingDefinition
    {
        $key = (string) $this->route('key');

        if (! SettingCatalog::has($key)) {
            throw new NotFoundHttpException(sprintf(
                'La llave de configuración «%s» no existe.',
                $key,
            ));
        }

        return SettingCatalog::get($key);
    }

    /**
     * El valor ya convertido al tipo que declara el catálogo.
     *
     * El JSON puede traer `"16.5"` o `16.5`, y `true`, `false`, `1`, `0`, `"1"` o `"0"` para los
     * booleanos: exactamente lo que la regla `boolean` de Laravel acepta. El servicio de
     * configuración exige el tipo exacto, así que la conversión vive aquí, en la frontera, y no
     * dentro del servicio: así el servicio sigue siendo estricto —que es lo que hace que el
     * tipado sirva— y la tolerancia se queda donde entra el JSON.
     *
     * `"true"` y `"false"` se RECHAZAN a propósito. Aceptar la cadena `"false"` obliga a decidir
     * qué significan `"no"`, `"off"` o `""`, y muchos lenguajes tratan cualquier cadena no vacía
     * como verdadera: un cliente que mandara `"false"` creyendo apagar el precorte ciego podría
     * encenderlo. Es mejor un 422 explícito que una ambigüedad silenciosa en un toggle de caja.
     */
    public function typedValue(): bool|int|string|float
    {
        $raw = $this->input('value');

        return match ($this->definition()->type) {
            SettingType::Bool => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            SettingType::Int => (int) $raw,
            SettingType::Decimal => (float) $raw,
            SettingType::String, SettingType::Enum => (string) $raw,
        };
    }

    /**
     * @return list<mixed>
     */
    private function rulesForType(SettingDefinition $definition): array
    {
        return match ($definition->type) {
            SettingType::Bool => ['boolean'],
            SettingType::Int => ['integer'],
            SettingType::Decimal => ['numeric'],
            SettingType::String => ['string', 'max:500'],
            SettingType::Enum => ['string', Rule::in($definition->allowed ?? [])],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $definition = $this->definition();

        return [
            'value.required' => 'Indica el valor.',
            'value.boolean' => 'Esta configuración sólo admite verdadero o falso.',
            'value.integer' => 'Esta configuración sólo admite un número entero.',
            'value.numeric' => 'Esta configuración sólo admite un número.',
            'value.in' => sprintf(
                'Valor no permitido. Los válidos son: %s.',
                implode(', ', $definition->allowed ?? []),
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['value' => 'el valor'];
    }
}
