<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryScheduleException extends Model
{
    protected $fillable = [
        'date',
        'type',
        'starts_at',
        'ends_at',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
