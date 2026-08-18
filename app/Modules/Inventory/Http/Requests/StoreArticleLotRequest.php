<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de un lote (D23).
 *
 * El código lo trae el proveedor impreso en la caja, así que se captura tal cual y es **único por artículo**: dos
 * proveedores distintos pueden usar la misma nomenclatura para productos que no tienen nada que ver.
 */
final class StoreArticleLotRequest extends FormRequest
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
        /** @var Article $article */
        $article = $this->route('article');

        return [
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_.\/]+$/',
                Rule::unique('article_lots', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->where('article_id', $article->id),
            ],

            // `null` = no caduca. Explícito para que el cliente no tenga que interpretar la ausencia.
            'expires_at' => ['nullable', 'date'],

            'received_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Article $article */
                $article = $this->route('article');

                // Sólo un artículo con lotes activados puede tenerlos. Un CHECK en `articles` impide la
                // contradicción de la bandera, pero nada impediría crear un lote de un artículo que no los
                // lleva — y ese lote sería **invisible para FEFO**: existencia que nadie encontraría.
                if (! $article->tracksLots()) {
                    $validator->errors()->add(
                        'code',
                        "«{$article->name}» no se controla por lotes. Actívalo en el artículo antes de capturar "
                        .'lotes, o su existencia quedaría fuera de la selección por caducidad.'
                    );
                }

                // La caducidad no puede ser anterior a la recepción. Hay un CHECK que lo garantiza y aquí sale
                // por campo: es un error de captura frecuente —teclear el año anterior— y con FEFO ese lote
                // saldría PRIMERO, vaciando lo que sí servía y dejando en el almacén lo que caduca de verdad.
                if ($this->filled('expires_at') && $this->date('expires_at')->lt($this->date('received_at'))) {
                    $validator->errors()->add(
                        'expires_at',
                        'La caducidad no puede ser anterior a la fecha de recepción.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe un lote con ese código para este artículo.',
            'code.regex' => 'El código del lote sólo admite letras, números, guiones, punto y diagonal.',
            'received_at.before_or_equal' => 'La fecha de recepción no puede estar en el futuro.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'el código del lote',
            'expires_at' => 'la caducidad',
            'received_at' => 'la fecha de recepción',
        ];
    }
}
