<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDiscountUsage extends Model
{
    protected $table = 'analytics_discount_usage';

    protected $fillable = [
        'date',
        'discount_id',
        'usage_count',
        'discount_amount',
        'generated_revenue',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'discount_id' => 'integer',
        'usage_count' => 'integer',
        'discount_amount' => 'integer',
        'generated_revenue' => 'integer',
    ];
}
