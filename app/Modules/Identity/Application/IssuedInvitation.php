<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\MembershipInvitation;

/**
 * Una invitación recién emitida y su enlace en claro (diseño de acceso, fase 3).
 *
 * El enlace sólo existe aquí y en el correo: la base guarda el hash del token. Quien invita lo recibe UNA vez, para
 * copiarlo y mandarlo por otro medio si el correo no llega.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public MembershipInvitation $invitation,
        public string $link,
    ) {}
}
