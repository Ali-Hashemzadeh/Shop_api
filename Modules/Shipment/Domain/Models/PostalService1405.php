<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Iran Post 1405 service definition. Internal to the Shipment module. The
 * canonical definitions live in Post1405Catalog; this row is the persisted mirror the
 * tariff rows reference.
 */
class PostalService1405 extends Model
{
    protected $table = 'postal_services_1405';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'title_fa',
        'scope',
        'content_kind',
        'package_kind',
        'rating_rule',
        'pdf_reference',
        'eligibility_policy',
        'tariff_year',
    ];

    protected function casts(): array
    {
        return [
            'tariff_year' => 'integer',
        ];
    }
}
