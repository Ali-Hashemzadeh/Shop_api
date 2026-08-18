<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDailySale extends Model
{
    protected $table = 'analytics_daily_sales';

    protected $fillable = [
        'date',
        'orders_count',
        'paid_orders_count',
        'cancelled_orders_count',
        'gross_revenue',
        'discount_amount',
        'coupon_amount',
        'refund_amount',
        'net_revenue',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'orders_count' => 'integer',
        'paid_orders_count' => 'integer',
        'cancelled_orders_count' => 'integer',
        'gross_revenue' => 'integer',
        'discount_amount' => 'integer',
        'coupon_amount' => 'integer',
        'refund_amount' => 'integer',
        'net_revenue' => 'integer',
    ];
}
