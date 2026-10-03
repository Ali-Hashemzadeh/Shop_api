<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Post tariff weight bracket. Internal to the Shipment module; queried through
 * PostTariffRepository. Prices are integer TOMANS (Money Unit Rule).
 */
class PostTariff extends Model
{
    protected $fillable = [
        'service_type',
        'package_type',
        'weight_from',
        'weight_to',
        'distance_type',
        'base_price',
        'extra_weight_price',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'weight_from' => 'integer',
            'weight_to' => 'integer',
            'base_price' => 'integer',
            'extra_weight_price' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }
}
