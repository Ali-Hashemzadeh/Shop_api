<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shipment extends Model
{
    protected $fillable = [
        'public_code',
        'order_id',
        'user_id',
        'method_code',
        'method_title',
        'method_type',
        'shipping_cost',
        'status',
        'address_snapshot',
        'delivery_slot_snapshot',
        'pickup_location_snapshot',
        'carrier_name',
        'tracking_number',
        'postal_receipt_media_id',
        'proof_media_id',
        'preparing_at',
        'ready_at',
        'handed_to_post_at',
        'out_for_delivery_at',
        'delivered_at',
        'ready_for_pickup_at',
        'picked_up_at',
        'cancelled_at',
        'receiver_name',
        'failure_reason',
        'note',
    ];

    protected $casts = [
        'shipping_cost' => 'integer',
        'address_snapshot' => 'array',
        'delivery_slot_snapshot' => 'array',
        'pickup_location_snapshot' => 'array',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'handed_to_post_at' => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'ready_for_pickup_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function histories(): HasMany
    {
        return $this->hasMany(ShipmentStatusHistory::class)->orderBy('id');
    }

    public static function generateUniquePublicCode(): string
    {
        do {
            $code = 'SH-'.strtoupper(Str::random(10));
        } while (self::where('public_code', $code)->exists());

        return $code;
    }
}
