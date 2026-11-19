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
 *
 * Execution tracking (started/finished/heartbeat, stage, structured failure,
 * retry link, request snapshot) is written only through
 * Application\Support\GenerationLifecycle's conditional updates — never with a
 * plain save — so concurrent workers and the stuck detector cannot overwrite
 * each other. Historical runs simply have those columns null.
 */
class AiReviewGeneration extends Model
{
    protected $fillable = [
        'product_id',
        'source_id',
        'model',
        'count_requested',
        'status',
        'current_stage',
        'analysis_json',
        'prompt_name',
        'prompt_version',
        'failure_reason',
        'failure_code',
        'failed_stage',
        'created_by',
        'started_at',
        'finished_at',
        'duration_ms',
        'last_heartbeat_at',
        'attempt_count',
        'retry_of_id',
        'request_context',
    ];

    protected $hidden = [
        'execution_token',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'source_id' => 'integer',
        'count_requested' => 'integer',
        'status' => GenerationStatus::class,
        'analysis_json' => 'array',
        'request_context' => 'array',
        'prompt_version' => 'integer',
        'created_by' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'duration_ms' => 'integer',
        'attempt_count' => 'integer',
        'retry_of_id' => 'integer',
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

    /** The run this one retries (null for an original run). */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    /** At most one row — `retry_of_id` is unique, so a retry chain is linear. */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }
}
