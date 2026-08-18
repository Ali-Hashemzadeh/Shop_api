<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsProductCategory extends Model
{
    protected $table = 'analytics_product_categories';

    protected $fillable = [
        'product_id',
        'category_id',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'category_id' => 'integer',
    ];
}
