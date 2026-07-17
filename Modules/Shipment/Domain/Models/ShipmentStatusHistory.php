<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id',
        'from_status',
        'to_status',
        'changed_by_user_id',
        'reason',
        'note',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
