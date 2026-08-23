<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Infrastructure\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
