<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period during which a delivery worker was responsible for a shipment.
 * The row with `unassigned_at === null` is the current assignment; reassignment
 * closes it and opens the next one, so history is never rewritten.
 */
class ShipmentDeliveryAssignment extends Model
{
    protected $fillable = [
        'shipment_id',
        'delivery_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'unassigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
