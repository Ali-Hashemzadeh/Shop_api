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
        // Order-level coupon state. Frozen once payment_pricing_finalized_at is set.
        'coupon_code',
        'coupon_discount_amount',
        'coupon_snapshot',
        'payment_pricing_finalized_at',
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
        'coupon_snapshot' => 'array',
        'total_amount' => 'integer',
        'shipping_cost' => 'integer',
        'tax_amount' => 'integer',
        'coupon_discount_amount' => 'integer',
        'payment_pricing_finalized_at' => 'datetime',
    ];

    /**
     * Merchandise subtotal: the sum of item line totals, already net of automatic
     * discounts and excluding shipping and tax.
     *
     * This is the one basis a coupon is ever calculated against, so it lives on the
     * model rather than being re-derived at each call site.
     */
    public function merchandiseSubtotal(): int
    {
        return (int) $this->items->sum('line_total');
    }

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
