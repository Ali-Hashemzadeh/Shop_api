<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsCategorySale extends Model
{
    protected $table = 'analytics_category_sales';

    protected $fillable = [
        'date',
        'category_id',
        'quantity_sold',
        'orders_count',
        'gross_revenue',
        'discount_amount',
        'net_revenue',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'category_id' => 'integer',
        'quantity_sold' => 'integer',
        'orders_count' => 'integer',
        'gross_revenue' => 'integer',
        'discount_amount' => 'integer',
        'net_revenue' => 'integer',
    ];
}
