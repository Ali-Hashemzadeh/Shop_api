<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryWorkingPeriod extends Model
{
    protected $fillable = [
        'weekday',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'weekday' => 'integer',
        'is_active' => 'boolean',
    ];
}
