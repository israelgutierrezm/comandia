<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * El centro de notificaciones del usuario (Tanda D2).
 *
 * Todo aquí está acotado a QUIEN mira: sus avisos son los dirigidos a su membresía o a su rol activo. No hay permiso fijo
 * —como «mi contexto» o «mis exportaciones»—, por eso las rutas son excepción declarada en `RoutePermissionTest`.
 */
final class NotificationController
{
    public function __construct(private readonly ContextHolder $context) {}

    public function index(): JsonResponse
    {
        $recent = $this->mine()->orderByDesc('created_at')->limit(30)->get();
        $unread = $this->mine()->whereNull('read_at')->count();

        return new JsonResponse([
            'data' => NotificationResource::collection($recent),
            'meta' => ['unread' => $unread],
        ]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $this->assertMine($notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return new JsonResponse(status: 204);
    }

    public function markAllRead(): JsonResponse
    {
        $this->mine()->whereNull('read_at')->update(['read_at' => now()]);

        return new JsonResponse(status: 204);
    }

    /**
     * Los avisos del usuario: los de su membresía o los de su rol activo.
     *
     * @return Builder<Notification>
     */
    private function mine(): Builder
    {
        $membershipId = (int) $this->context->get()->requireMembership()->id;
        $roleId = (int) $this->context->get()->requireActiveRole()->id;

        return Notification::query()
            ->where(fn ($q) => $q
                ->where('recipient_membership_id', $membershipId)
                ->orWhere('recipient_role_id', $roleId));
    }

    private function assertMine(Notification $notification): void
    {
        $membershipId = (int) $this->context->get()->requireMembership()->id;
        $roleId = (int) $this->context->get()->requireActiveRole()->id;

        $mine = (int) $notification->recipient_membership_id === $membershipId
            || (int) $notification->recipient_role_id === $roleId;

        if (! $mine) {
            abort(404);
        }
    }
}
