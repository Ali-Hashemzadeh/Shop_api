<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDeliveryStat extends Model
{
    protected $table = 'analytics_delivery_stats';

    protected $fillable = [
        'date',
        'method',
        'assigned_count',
        'delivered_count',
        'failed_count',
        'total_delivery_minutes',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'method' => 'string',
        'assigned_count' => 'integer',
        'delivered_count' => 'integer',
        'failed_count' => 'integer',
        'total_delivery_minutes' => 'integer',
    ];
}
