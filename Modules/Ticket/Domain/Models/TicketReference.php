<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ticket\Domain\Enums\TicketReferenceType;

/**
 * A loose pointer from a ticket to some other entity the customer is asking
 * about (an order, a product, …). PRIVATE to the Ticket module.
 *
 * `reference_type` is an enum whitelist; `reference_code` is the public code the
 * customer quoted and `reference_id` an optional numeric id. There is no FK to
 * any other module's table — Ticket never resolves or validates the target, so
 * it stays fully decoupled (a reference to a deleted entity is inert). The
 * optional `snapshot` may hold a denormalized copy if a caller ever populates it.
 */
class TicketReference extends Model
{
    protected $fillable = [
        'ticket_id',
        'reference_type',
        'reference_id',
        'reference_code',
        'snapshot',
    ];

    protected $casts = [
        'reference_type' => TicketReferenceType::class,
        'snapshot' => 'array',
    ];

    /**
     * @return BelongsTo<Ticket, TicketReference>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
