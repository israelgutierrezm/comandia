<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rol que la persona invitada recibirá al aceptar (diseño de acceso, fase 3).
 *
 * Existe como modelo, y no sólo como pivote, para que la fila nazca con su `tenant_id` y el global scope de negocio la
 * cubra como a cualquier dato de dominio (ADR-002).
 */
final class MembershipInvitationRole extends DomainModel
{
    public $timestamps = false;

    protected $table = 'membership_invitation_roles';

    protected $fillable = [
        'invitation_id',
        'role_id',
    ];

    /**
     * @return BelongsTo<MembershipInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(MembershipInvitation::class, 'invitation_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
