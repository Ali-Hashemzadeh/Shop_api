<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A configurable bucket a ticket is filed under (billing, technical, …).
 * PRIVATE to the Ticket module. Tickets store the immutable `code`, not the id,
 * so renaming a category never rewrites historical tickets.
 */
class TicketCategory extends Model
{
    protected $fillable = [
        'name',
        'code',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
