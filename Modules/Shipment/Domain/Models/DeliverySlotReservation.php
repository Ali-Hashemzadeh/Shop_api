<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySlotReservation extends Model
{
    protected $fillable = [
        'delivery_slot_id',
        'order_id',
        'user_id',
        'status',
        'expires_at',
        'confirmed_at',
        'released_at',
        'completed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'released_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
