<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasPublicCode;

    protected $fillable = [
        'public_code',
        'order_id',
        'user_id',
        'assigned_delivery_user_id',
        'delivery_assigned_at',
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
        'delivery_verification_code_hash',
        'delivery_verification_issued_at',
        'delivery_verification_verified_at',
    ];

    /**
     * The verification hash never leaves the module: it is hidden here so it can
     * never be serialised by accident, and no DTO or resource carries it either.
     */
    protected $hidden = [
        'delivery_verification_code_hash',
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
        'delivery_assigned_at' => 'datetime',
        'delivery_verification_issued_at' => 'datetime',
        'delivery_verification_verified_at' => 'datetime',
    ];

    public function histories(): HasMany
    {
        return $this->hasMany(ShipmentStatusHistory::class)->orderBy('id');
    }

    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(ShipmentDeliveryAssignment::class)->orderBy('id');
    }

    /**
     * Customer/admin-facing handle. New shipments get `bds-XXXXXX`; shipments
     * issued under the earlier `SH-<10 random>` scheme keep their code forever
     * and stay fully addressable — the route constraints accept both.
     *
     * generateUniquePublicCode() itself comes from HasPublicCode, which checks
     * `shipments.public_code` and nothing else.
     */
    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::Shipment;
    }
}
