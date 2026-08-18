<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsProcessedEvent extends Model
{
    protected $table = 'analytics_processed_events';

    protected $fillable = [
        'event_id',
        'event_name',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
