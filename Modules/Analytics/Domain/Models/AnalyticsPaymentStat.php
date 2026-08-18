<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsPaymentStat extends Model
{
    protected $table = 'analytics_payment_stats';

    protected $fillable = [
        'date',
        'gateway',
        'successful_count',
        'failed_count',
        'cancelled_count',
        'total_amount',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'gateway' => 'string',
        'successful_count' => 'integer',
        'failed_count' => 'integer',
        'cancelled_count' => 'integer',
        'total_amount' => 'integer',
    ];
}
