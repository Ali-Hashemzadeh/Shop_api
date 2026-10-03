<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;

/**
 * A single AI review draft. Editable by an admin, then approved (published to
 * the Review module) or rejected. `published_review_id` is the loose back-link
 * to the created review for end-to-end traceability.
 */
class AiGeneratedReview extends Model
{
    protected $fillable = [
        'generation_id',
        'product_id',
        'name',
        'rating',
        'title',
        'body',
        'status',
        'approved_by',
        'approved_at',
        'published_review_id',
    ];

    protected $casts = [
        'generation_id' => 'integer',
        'product_id' => 'integer',
        'rating' => 'integer',
        'status' => DraftStatus::class,
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'published_review_id' => 'integer',
    ];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiReviewGeneration::class, 'generation_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AiGeneratedReviewVersion::class, 'generated_review_id');
    }
}
