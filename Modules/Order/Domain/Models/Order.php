<?php

namespace Modules\Order\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'shipping_cost',
        'tax_amount',
        'shipment_method_id',
        'shipping_address',
        'transaction_ref',
        'notes',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'total_amount' => 'integer',
        'shipping_cost' => 'integer',
        'tax_amount' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
