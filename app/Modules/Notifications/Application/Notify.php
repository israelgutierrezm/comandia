<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Models\Notification;

/**
 * Crea avisos internos (Tanda D2, §6.9). Vive en `Notifications` (kernel) para que cualquier módulo avise sin acoplarse.
 *
 * Los productores (el job de exportación, y a futuro stock bajo / diferencia de corte / reporte programado) llaman aquí;
 * el tenant lo pone el global scope. Un aviso no debe poder tumbar la operación que lo generó, así que quien llama lo
 * hace por evento/tras-commit donde aplique.
 */
final class Notify
{
    public function toMembership(int $membershipId, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        Notification::create([
            'recipient_membership_id' => $membershipId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);
    }

    public function toRole(int $roleId, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        Notification::create([
            'recipient_role_id' => $roleId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);
    }
}
