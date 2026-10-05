<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;

/**
 * El resultado de un alta de personal: la membresía y, si va a entrar al sistema, su invitación con el enlace en claro
 * (diseño de acceso, fase 3). El enlace se muestra UNA vez a quien la dio de alta.
 */
final readonly class CreatedMembership
{
    public function __construct(
        public TenantMembership $membership,
        public ?IssuedInvitation $invitation = null,
    ) {}
}
