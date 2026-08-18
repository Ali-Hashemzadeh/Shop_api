<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDriverStat extends Model
{
    protected $table = 'analytics_driver_stats';

    protected $fillable = [
        'date',
        'driver_id',
        'assigned_count',
        'completed_count',
        'failed_count',
        'total_delivery_minutes',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'driver_id' => 'integer',
        'assigned_count' => 'integer',
        'completed_count' => 'integer',
        'failed_count' => 'integer',
        'total_delivery_minutes' => 'integer',
    ];
}
