<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single tunable shipping formula constant (carrier + key => value). Internal to
 * the Shipment module; read through ShippingParameterRepository, which casts `value`
 * per `type`. No cross-module code touches this model.
 */
class ShippingParameter extends Model
{
    protected $fillable = [
        'carrier',
        'key',
        'value',
        'type',
        'description',
    ];
}
