<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Application\Actions\ProcessGenerationAction;
use Modules\ProductReviewAI\Application\Actions\RecordJobFailureAction;
use Throwable;

/**
 * Runs the AI generation pipeline off the HTTP request. Scraping and AI calls
 * never happen inside a web request.
 *
 * The product context (title/description) is captured at request time and
 * carried in the payload (and, for new runs, in the generation's immutable
 * request snapshot), so the job never needs a cross-module Catalog lookup.
 *
 * Three different "retries" exist — keep them apart:
 *  - HTTP retry: none. The source/AI adapters make one request per call.
 *  - Queue delivery retry: disabled ($tries = 1). Re-running the pipeline
 *    automatically would repeat paid AI calls. A redelivery (worker crash,
 *    retry_after elapsed) cannot start a second pipeline: the claim is atomic.
 *  - Admin retry: POST /admin/ai-reviews/generations/{id}/retry creates a NEW
 *    generation row linked by retry_of_id. This is the recovery mechanism.
 *
 * A business-level failure (source/AI error) is recorded on the generation and
 * the job itself completes normally — it does not appear in `failed_jobs`.
 * Only failures that escape the pipeline reach failed().
 */
class GenerateProductAIReviewsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Seconds; config('product_review_ai.queue.job_timeout'). Must be < the connection's retry_after. */
    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, mixed>  $productContext
     */
    public function __construct(
        public readonly int $generationId,
        public readonly array $productContext = [],
    ) {
        $this->timeout = max(1, (int) config('product_review_ai.queue.job_timeout', 1200));
    }

    public function handle(ProcessGenerationAction $action): void
    {
        $action->handle($this->generationId, $this->productContext, $this->executionToken(), $this->job?->uuid());
    }

    /**
     * Failures that escaped the pipeline. Must never throw: a secondary error
     * here is logged, not propagated.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            app(RecordJobFailureAction::class)->handle($this->generationId, $this->executionToken(), $exception);
        } catch (Throwable $e) {
            Log::error('ProductReviewAI could not record a job failure', [
                'generation_id' => $this->generationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Identifies this *delivery*: the driver-independent payload uuid plus the
     * attempt number. A redelivered copy has a higher attempt number, so it can
     * never act on a claim made by an earlier delivery.
     */
    private function executionToken(): ?string
    {
        $uuid = $this->job?->uuid();

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return $uuid.':'.$this->job->attempts();
    }
}
