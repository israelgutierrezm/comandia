<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * Un aviso interno (Tanda D2). Va a una membresía o a un rol; se marca leído con `read_at`.
 */
final class Notification extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'notifications';

    public $timestamps = false;

    protected $fillable = [
        'recipient_membership_id',
        'recipient_role_id',
        'type',
        'title',
        'body',
        'url',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
