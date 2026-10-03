<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;

/**
 * One AI generation run. Records the model + prompt version used (immutable
 * history), its status, and the stage-1 analysis. Never deleted.
 */
class AiReviewGeneration extends Model
{
    protected $fillable = [
        'product_id',
        'source_id',
        'model',
        'count_requested',
        'status',
        'analysis_json',
        'prompt_name',
        'prompt_version',
        'failure_reason',
        'created_by',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'source_id' => 'integer',
        'count_requested' => 'integer',
        'status' => GenerationStatus::class,
        'analysis_json' => 'array',
        'prompt_version' => 'integer',
        'created_by' => 'integer',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReviewSource::class, 'source_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AiGeneratedReview::class, 'generation_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AiReviewGenerationLog::class, 'generation_id');
    }
}
