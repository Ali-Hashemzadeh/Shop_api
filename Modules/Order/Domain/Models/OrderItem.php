<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'sku',
        'product_title',
        'variant_attributes',
        'product_snapshot',
        'quantity',
        'max_quantity_per_order_snapshot',
        // Catalog's base_price at checkout.
        'regular_price_per_unit',
        // The winning automatic reduction, per unit.
        'automatic_discount_amount_per_unit',
        'automatic_discount_snapshot',
        // What was actually charged per unit, before any order-level coupon.
        'price_per_unit',
        // Legacy: the old Catalog cross-out price. Retained so historical orders keep
        // rendering, but never written by new orders.
        'compare_at_price',
        'line_total',
    ];

    protected $casts = [
        'variant_attributes' => 'array',
        'product_snapshot' => 'array',
        'automatic_discount_snapshot' => 'array',
        'quantity' => 'integer',
        'max_quantity_per_order_snapshot' => 'integer',
        'regular_price_per_unit' => 'integer',
        'automatic_discount_amount_per_unit' => 'integer',
        'price_per_unit' => 'integer',
        'compare_at_price' => 'integer',
        'line_total' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
