<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ticket\Domain\Enums\TicketMessageType;

/**
 * One entry in a ticket conversation. PRIVATE to the Ticket module.
 *
 * `user_id` is a loose Identity reference (the author; null for a system
 * message). `type` decides visibility — an `internal_note` is never returned on
 * a customer surface.
 */
class TicketMessage extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
        'media_ids',
        'type',
    ];

    protected $casts = [
        'type' => TicketMessageType::class,
        // Pre-uploaded Media ids (loose coupling — no FK into media).
        'media_ids' => 'array',
    ];

    /**
     * @return BelongsTo<Ticket, TicketMessage>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
