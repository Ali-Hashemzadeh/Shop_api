<?php

namespace Modules\Order\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasPublicCode;

    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'shipping_cost',
        'tax_amount',
        'shipment_method_id',
        'shipment_method_code',
        'shipping_address',
        'shipment_snapshot',
        'customer_snapshot',
        'transaction_ref',
        'notes',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'shipment_snapshot' => 'array',
        'customer_snapshot' => 'array',
        'total_amount' => 'integer',
        'shipping_cost' => 'integer',
        'tax_amount' => 'integer',
    ];

    /**
     * Customer-facing handle (`bdo-XXXXXX`) for support, SMS, and order search.
     * Additive only: the integer id stays the primary key, the `order_items`
     * foreign-key target, and the identifier Payment, Shipment, admin filters,
     * scheduled commands, and integration events all use internally.
     */
    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::Order;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
