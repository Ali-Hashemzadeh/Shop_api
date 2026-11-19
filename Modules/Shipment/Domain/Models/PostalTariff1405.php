<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Iran Post 1405 base-rate row (closed weight bracket in grams). Internal to the
 * Shipment module; read through PostalTariff1405Repository. Prices are integer TOMANS.
 */
class PostalTariff1405 extends Model
{
    protected $table = 'postal_tariffs_1405';

    protected $fillable = [
        'service_code',
        'zone_code',
        'weight_from',
        'weight_to',
        'base_price_toman',
        'extra_per_started_kg_toman',
        'extra_above_grams',
        'tariff_year',
    ];

    protected function casts(): array
    {
        return [
            'weight_from' => 'integer',
            'weight_to' => 'integer',
            'base_price_toman' => 'integer',
            'extra_per_started_kg_toman' => 'integer',
            'extra_above_grams' => 'integer',
            'tariff_year' => 'integer',
        ];
    }
}
