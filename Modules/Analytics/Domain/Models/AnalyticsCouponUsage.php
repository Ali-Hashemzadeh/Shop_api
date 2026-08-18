<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsCouponUsage extends Model
{
    protected $table = 'analytics_coupon_usage';

    protected $fillable = [
        'date',
        'coupon_id',
        'usage_count',
        'discount_amount',
        'generated_revenue',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'coupon_id' => 'integer',
        'usage_count' => 'integer',
        'discount_amount' => 'integer',
        'generated_revenue' => 'integer',
    ];
}
