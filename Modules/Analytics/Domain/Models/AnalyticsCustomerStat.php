<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsCustomerStat extends Model
{
    protected $table = 'analytics_customer_stats';

    protected $fillable = [
        'customer_id',
        'orders_count',
        'total_spent',
        'total_discount_received',
        'average_order_value',
        'first_order_at',
        'last_order_at',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'orders_count' => 'integer',
        'total_spent' => 'integer',
        'total_discount_received' => 'integer',
        'average_order_value' => 'integer',
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
    ];
}
