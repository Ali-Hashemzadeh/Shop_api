<?php

namespace Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single directed adjacency between two provinces. Internal to Identity — no other
 * module imports it; distance questions are answered through
 * IdentityManagerInterface::getProvinceDistanceType().
 */
class ProvinceNeighbor extends Model
{
    protected $fillable = [
        'province_id',
        'neighbor_province_id',
    ];

    protected function casts(): array
    {
        return [
            'province_id' => 'integer',
            'neighbor_province_id' => 'integer',
        ];
    }
}
