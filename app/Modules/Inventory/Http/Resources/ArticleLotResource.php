<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Application\LotCalendar;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Shared\Application\Context\ContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleLot
 *
 * Un lote con su caducidad. `expires_at` en `null` significa **no caduca** —la sal, el azúcar— y es distinto de
 * una fecha muy lejana: poner el año 2099 sería inventar un dato que alguien leería como real.
 */
final class ArticleLotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vencido = $this->isExpiredOn($this->today());

        return [
            'ulid' => $this->ulid,
            'code' => $this->code,

            'expires_at' => $this->expires_at?->toDateString(),
            'received_at' => $this->received_at?->toDateString(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // La misma regla que la salida automática (D377): activo y con la caducidad vigente.
            'can_be_issued' => $this->status->canBeIssued() && ! $vencido,

            // Ya caducó a día de hoy. Se calcula en el SERVIDOR y no en el cliente porque depende de un reloj:
            // dos navegadores mal ajustados darían respuestas distintas sobre el mismo lote, y uno de los dos
            // dejaría surtir mercancía vencida.
            //
            // Por FECHA de la sucursal en la que se opera, como la salida automática (D377): el lote vale todo su
            // último día. Antes se comparaba con la hora UTC y salía «caducado» el mismo día en que todavía se surtía.
            'is_expired' => $vencido,

            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Hoy en la sucursal del contexto; sin sucursal activa, el día del negocio —el mismo que usan la sonda de compras y
     * la tarea de cada noche—, nunca la hora UTC de la aplicación.
     */
    private function today(): CarbonImmutable
    {
        $zona = app(ContextHolder::class)->getOrNull()?->activeBranch?->timezone;

        return is_string($zona) && $zona !== ''
            ? CarbonImmutable::now($zona)->startOfDay()
            : app(LotCalendar::class)->todayInBusiness();
    }
}
