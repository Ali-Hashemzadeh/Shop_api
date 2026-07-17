<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliverySlot extends Model
{
    protected $fillable = [
        'delivery_date',
        'starts_at',
        'ends_at',
        'capacity',
        'admin_reserved_capacity',
        'status',
        'note',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'admin_reserved_capacity' => 'integer',
    ];

    /** Normalized 'Y-m-d' delivery date, robust across SQLite/MySQL storage. */
    public function dateString(): string
    {
        return substr((string) $this->delivery_date, 0, 10);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(DeliverySlotReservation::class);
    }
}
