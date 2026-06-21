<?php

namespace Modules\Catalog\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'type',
        'is_default',
        'base_price',
        'compare_at_price',
        'media_id',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'base_price' => 'integer',
            'compare_at_price' => 'integer',
            'attributes' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
