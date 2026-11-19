<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Application\Jobs\GenerateProductAIReviewsJob;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Exceptions\GenerationDispatchException;
use Throwable;

/**
 * Pushes a committed generation onto the queue. Always called AFTER the row's
 * transaction commits, so a worker can never pick up a job for a row it cannot
 * see yet.
 *
 * If the push itself throws (queue backend down), the run is failed with
 * QUEUE_PROCESSING_ERROR — conditionally, only while it is still `pending` —
 * so it never lingers as an orphaned pending record, and a
 * GenerationDispatchException (503) tells the caller. A push that "succeeded"
 * but whose message is later lost cannot be detected here; that case surfaces
 * as a suspected-stuck pending run in monitoring.
 */
final class GenerationDispatcher
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly GenerationLifecycle $lifecycle,
        private readonly GenerationLogWriter $log,
    ) {}

    /**
     * @param  array<string, mixed>  $productContext
     */
    public function dispatch(int $generationId, array $productContext): void
    {
        try {
            $this->bus->dispatch(new GenerateProductAIReviewsJob($generationId, $productContext));
        } catch (Throwable $e) {
            Log::error('ProductReviewAI generation could not be queued', [
                'generation_id' => $generationId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $failed = false;

            try {
                $failed = $this->lifecycle->fail(
                    $generationId,
                    null,
                    GenerationFailureCode::QueueProcessingError,
                    'The generation job could not be pushed onto the queue.',
                    GenerationStage::Queued,
                    null,
                    includeUnclaimed: true,
                );

                if ($failed) {
                    $this->log->failure($generationId, GenerationLogType::ProcessingFailed, 'The generation job could not be pushed onto the queue.', [
                        'stage' => GenerationStage::Queued->value,
                        'failure_code' => GenerationFailureCode::QueueProcessingError->value,
                    ]);
                }
            } catch (Throwable $recordError) {
                Log::error('ProductReviewAI could not record the dispatch failure', [
                    'generation_id' => $generationId,
                    'error' => $recordError->getMessage(),
                ]);
            }

            if ($failed) {
                throw new GenerationDispatchException($generationId, $e);
            }

            // The row is no longer pending: with the synchronous driver the job
            // already ran inline and recorded its own outcome. Surface as-is.
            throw $e;
        }
    }
}
