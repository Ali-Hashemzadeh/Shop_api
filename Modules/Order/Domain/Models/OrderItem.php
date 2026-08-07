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
        'price_per_unit',
        'compare_at_price',
        'line_total',
    ];

    protected $casts = [
        'variant_attributes' => 'array',
        'product_snapshot' => 'array',
        'quantity' => 'integer',
        'max_quantity_per_order_snapshot' => 'integer',
        'price_per_unit' => 'integer',
        'compare_at_price' => 'integer',
        'line_total' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
