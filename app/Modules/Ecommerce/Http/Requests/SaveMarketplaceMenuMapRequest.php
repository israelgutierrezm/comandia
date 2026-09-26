<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Domain\Enums\DeliveryChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mapear un ítem del menú de un marketplace a un artículo (ADR-015, Fase 2). El permiso lo gatea la ruta
 * (`ecommerce.store.configure`); aquí sólo se validan los datos. Que el artículo exista en el negocio y sea
 * vendible lo decide el servicio.
 */
final class SaveMarketplaceMenuMapRequest extends FormRequest
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
            'channel' => ['required', Rule::enum(DeliveryChannelType::class)],
            'external_item_id' => ['required', 'string', 'max:191'],
            'article_ulid' => ['required', 'string', 'size:26'],
        ];
    }
}
