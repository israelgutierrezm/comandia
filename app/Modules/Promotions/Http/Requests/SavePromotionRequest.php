<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una promoción (§6.3).
 *
 * ## El rango lo valida la BD; la completitud por tipo, aquí
 *
 * La base de datos no puede expresar «si el tipo es porcentaje entonces `percent_value` es obligatorio» de forma
 * portable, pero sí «`percent_value`, si existe, está entre 0 y 100». Así que el reparto es deliberado: los `required_if`
 * de aquí garantizan que cada tipo traiga sus columnas, y los CHECK de la migración garantizan el rango.
 *
 * ## Los días de la semana llegan como lista, no como máscara
 *
 * El cliente manda `weekdays` (0 = domingo … 6 = sábado); el controlador arma la máscara de bits. Pedirle al frontend
 * que calcule un entero de banderas sería filtrar un detalle de almacenamiento a la interfaz.
 */
final class SavePromotionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:percentage,amount,nxm,special_price'],

            // Cada tipo trae lo suyo.
            'percent_value' => ['required_if:type,percentage', 'nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'amount_value' => ['required_if:type,amount,special_price', 'nullable', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'buy_quantity' => ['required_if:type,nxm', 'nullable', 'integer', 'min:2', 'max:99'],
            'pay_quantity' => ['required_if:type,nxm', 'nullable', 'integer', 'min:1', 'lt:buy_quantity'],

            // Vigencia. Todo opcional = «sin límite por ese lado».
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'daily_start' => ['nullable', 'date_format:H:i,H:i:s'],
            'daily_end' => ['nullable', 'date_format:H:i,H:i:s', 'required_with:daily_start'],

            // Días de la semana como lista de 0..6. Vacío/ausente = todos.
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:0,6', 'distinct'],

            'all_branches' => ['required', 'boolean'],
            // Si no aplica a todas, exige al menos una sucursal — y que exista en el negocio.
            'branch_ulids' => ['required_if:all_branches,false', 'array', 'min:1'],
            'branch_ulids.*' => [
                'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', $tenantId),
            ],

            // A qué aplica: al menos un objetivo, cada uno artículo O categoría, no ambos.
            'targets' => ['required', 'array', 'min:1', 'max:200'],
            'targets.*.article_ulid' => [
                'nullable', 'required_without:targets.*.category_ulid', 'string', 'size:26',
                Rule::exists('articles', 'ulid')->where('tenant_id', $tenantId),
            ],
            'targets.*.category_ulid' => [
                'nullable', 'required_without:targets.*.article_ulid', 'string', 'size:26',
                Rule::exists('article_categories', 'ulid')->where('tenant_id', $tenantId),
            ],

            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_stackable' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,inactive'],

            // El bloqueo optimista: obligatorio al EDITAR, ignorado al crear (lo pone el controlador).
            'version' => [Rule::requiredIf($this->isMethod('PATCH')), 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'percent_value.required_if' => 'Un descuento por porcentaje necesita el porcentaje.',
            'amount_value.required_if' => 'Este tipo de promoción necesita un monto.',
            'buy_quantity.required_if' => 'Un NxM necesita cuántas unidades se compran.',
            'pay_quantity.required_if' => 'Un NxM necesita cuántas se pagan.',
            'pay_quantity.lt' => 'En un NxM se paga menos de lo que se compra: 2x1 es comprar 2, pagar 1.',
            'branch_ulids.required_if' => 'Indica en qué sucursales aplica, o marca que aplica en todas.',
            'targets.required' => 'Una promoción necesita al menos un artículo o categoría a la que aplicar.',
            'daily_end.required_with' => 'Si defines una hora de inicio, define también la de fin.',
        ];
    }
}
