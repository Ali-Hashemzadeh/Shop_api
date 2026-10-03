<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * A customer support ticket. PRIVATE to the Ticket module.
 *
 * `user_id` (the customer) and `assigned_to` (a support user) are loose Identity
 * references — no FK, no import of the User model — resolved through
 * IdentityManagerInterface. The public-facing handle is the `ticket_number`
 * (`bdk-XXXXXX`); the integer id stays the structural key for messages and
 * references.
 */
class Ticket extends Model
{
    use HasPublicCode;

    protected $fillable = [
        'user_id',
        'assigned_to',
        'subject',
        'category',
        'priority',
        'status',
        'last_message_at',
        'closed_at',
    ];

    protected $casts = [
        'priority' => TicketPriority::class,
        'status' => TicketStatus::class,
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::Ticket;
    }

    public static function publicCodeColumn(): string
    {
        return 'ticket_number';
    }

    /**
     * @return HasMany<TicketMessage>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * @return HasMany<TicketReference>
     */
    public function references(): HasMany
    {
        return $this->hasMany(TicketReference::class);
    }
}
