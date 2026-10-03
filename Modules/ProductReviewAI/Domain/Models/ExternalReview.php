<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single customer review collected from an external marketplace. `raw_data`
 * keeps the untouched source payload.
 */
class ExternalReview extends Model
{
    protected $fillable = [
        'mapping_id',
        'rating',
        'title',
        'body',
        'raw_data',
        'collected_at',
    ];

    protected $casts = [
        'mapping_id' => 'integer',
        'rating' => 'integer',
        'raw_data' => 'array',
        'collected_at' => 'datetime',
    ];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ExternalProductMapping::class, 'mapping_id');
    }
}
