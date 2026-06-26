<?php

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
        'quantity',
        'price_per_unit',
        'line_total',
    ];

    protected $casts = [
        'variant_attributes' => 'array',
        'quantity' => 'integer',
        'price_per_unit' => 'integer',
        'line_total' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
