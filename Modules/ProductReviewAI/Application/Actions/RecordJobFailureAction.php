<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Application\Support\FailureClassifier;
use Modules\ProductReviewAI\Application\Support\GenerationLifecycle;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Throwable;

/**
 * Backs GenerateProductAIReviewsJob::failed() — failures that escaped the
 * pipeline's own handling (an error before/while claiming, a failure while
 * recording a failure, a worker timeout, a redelivery past $tries).
 *
 * It only ever fails a run that is still `pending` (nobody claimed it) or that
 * is `processing` *and owned by the failing delivery* (same execution token).
 * A completed/failed run is never touched, and a redelivered copy
 * (MaxAttemptsExceededException) never fails a run another worker may still be
 * processing — that case is left to stuck-generation detection.
 */
class RecordJobFailureAction
{
    public function __construct(
        private readonly GenerationLifecycle $lifecycle,
        private readonly GenerationLogWriter $log,
        private readonly FailureClassifier $classifier,
    ) {}

    public function handle(int $generationId, ?string $executionToken, ?Throwable $e): void
    {
        /** @var AiReviewGeneration|null $generation */
        $generation = AiReviewGeneration::query()->find($generationId);

        if ($generation === null) {
            return;
        }

        $status = $generation->status;

        if ($status !== GenerationStatus::Pending && $status !== GenerationStatus::Processing) {
            return; // terminal — never overwritten
        }

        $stage = $generation->current_stage !== null
            ? GenerationStage::tryFrom((string) $generation->current_stage)
            : null;

        $redelivered = $e instanceof MaxAttemptsExceededException && ! $e instanceof TimeoutExceededException;

        if ($e instanceof TimeoutExceededException) {
            $code = GenerationFailureCode::ProcessingTimeout;
            $reason = 'The queue worker stopped the job after it exceeded its timeout.';
        } elseif ($redelivered) {
            $code = GenerationFailureCode::QueueProcessingError;
            $reason = 'The queue delivered the job again after its only attempt (a worker stopped, or retry_after is shorter than the job timeout).';
        } else {
            $code = $e !== null ? $this->classifier->classify($e, $stage) : GenerationFailureCode::QueueProcessingError;
            $code = $code === GenerationFailureCode::UnknownError ? GenerationFailureCode::QueueProcessingError : $code;
            $reason = $e !== null ? $this->classifier->reasonFor($e, $code) : $code->description();
        }

        Log::error('ProductReviewAI generation job failed', [
            'generation_id' => $generationId,
            'status' => $status->value,
            'failure_code' => $code->value,
            'exception' => $e !== null ? $e::class : null,
            'error' => $e?->getMessage(),
        ]);

        $failed = $this->lifecycle->fail(
            $generationId,
            $redelivered ? null : $executionToken,
            $code,
            $reason,
            $stage ?? GenerationStage::Queued,
            $generation->started_at,
            includeUnclaimed: true,
        );

        if ($failed) {
            $this->log->failure($generationId, GenerationLogType::ProcessingFailed, $reason, [
                'stage' => ($stage ?? GenerationStage::Queued)->value,
                'failure_code' => $code->value,
            ]);

            return;
        }

        if ($redelivered) {
            $this->log->success($generationId, GenerationLogType::ProcessingSkipped, [
                'current_status' => $status->value,
                'reason' => 'redelivered_after_max_attempts',
            ]);
        }
    }
}
