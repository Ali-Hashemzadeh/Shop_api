<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\ProductReviewAI\Application\Support\FailureClassifier;
use Modules\ProductReviewAI\Application\Support\GenerationLifecycle;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Application\Support\SourceResolver;
use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestContext;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\GenerationOwnershipLostException;
use Modules\ProductReviewAI\Domain\Exceptions\MappingNotFoundException;
use Modules\ProductReviewAI\Domain\Exceptions\SourceNotConfiguredException;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;
use Modules\ProductReviewAI\Infrastructure\Support\AIProviderFactory;
use Throwable;

/**
 * The full generation pipeline, run inside the queue job:
 *
 *   claim (pending → processing, stage initializing) → collecting_reviews →
 *   analyzing_reviews → generating_reviews → saving_drafts → completed/finished
 *
 * - The claim is one conditional UPDATE: a duplicate delivery (or a delivery for
 *   a run that already completed/failed) does not start a second pipeline — it
 *   is logged as PROCESSING_SKIPPED and returns.
 * - Each stage is recorded (with a heartbeat) before it executes, and only by
 *   the delivery that owns the claim. No transaction spans an HTTP/AI call.
 * - Pipeline failures are caught, classified (failure_code + failed_stage +
 *   sanitized failure_reason) and recorded — the run ends `failed`; nothing is
 *   rethrown, so the queue job itself succeeds. A business-level failed run
 *   therefore has no `failed_jobs` row.
 * - If recording the failure itself fails (database down), that error is
 *   rethrown so the job's failed() handler gets a second chance.
 * - If ownership is lost mid-run (the stuck detector reconciled it as
 *   abandoned), the worker stops and writes nothing — a late worker can never
 *   turn a terminal `failed` into `completed`.
 *
 * The run executes from its immutable request snapshot (request_context);
 * runs queued before snapshots existed fall back to the payload context and the
 * current mapping/prompt, exactly as before.
 */
class ProcessGenerationAction
{
    public function __construct(
        private readonly SourceResolver $sources,
        private readonly CollectExternalReviewsAction $collect,
        private readonly RunAnalysisAction $analyze,
        private readonly RunGenerationAction $generate,
        private readonly AIProviderFactory $providers,
        private readonly GenerationLifecycle $lifecycle,
        private readonly GenerationLogWriter $log,
        private readonly FailureClassifier $classifier,
    ) {}

    /**
     * @param  array<string, mixed>  $productContext  payload context (legacy fallback)
     * @param  string|null  $executionToken  identifies this delivery; random when run outside a queue
     */
    public function handle(
        int $generationId,
        array $productContext,
        ?string $executionToken = null,
        ?string $queueJobUuid = null,
    ): void {
        if (! AiReviewGeneration::query()->whereKey($generationId)->exists()) {
            return;
        }

        $token = $executionToken ?? (string) Str::uuid();

        $this->lifecycle->recordDelivery($generationId, $queueJobUuid);

        $startedAt = $this->lifecycle->claim($generationId, $token);

        if ($startedAt === null) {
            $this->logSkippedDelivery($generationId);

            return;
        }

        /** @var AiReviewGeneration $generation */
        $generation = AiReviewGeneration::query()->with('source')->findOrFail($generationId);

        $this->log->success($generationId, GenerationLogType::ProcessingStarted, [
            'stage' => GenerationStage::Initializing->value,
            'attempt' => (int) $generation->attempt_count,
        ]);

        $this->run($generation, $productContext, $token, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $payloadContext
     */
    private function run(AiReviewGeneration $generation, array $payloadContext, string $token, CarbonInterface $startedAt): void
    {
        $stage = GenerationStage::Initializing;
        $context = GenerationRequestContext::fromArray($generation->request_context);
        $productContext = $context?->productContext() ?? $payloadContext;

        try {
            [$adapter, $mapping] = $this->resolveSourceAndMapping($generation, $context);
            $provider = $this->providers->make($context?->aiProvider);
            $model = $context?->aiModel ?? (string) $generation->model;

            $stage = GenerationStage::CollectingReviews;
            $this->lifecycle->enterStage($generation->id, $token, $stage);
            $reviews = $this->collect->handle($generation->id, $mapping, $adapter, $context?->reviewLimit);

            $stage = GenerationStage::AnalyzingReviews;
            $this->lifecycle->enterStage($generation->id, $token, $stage);
            $analysis = $this->analyze->handle(
                $generation->id,
                $reviews,
                $productContext,
                $provider,
                $model,
                $context?->analysisPromptVersion,
            );
            $this->lifecycle->saveAnalysis($generation->id, $token, $analysis->toArray());

            $stage = GenerationStage::GeneratingReviews;
            $this->lifecycle->enterStage($generation->id, $token, $stage);
            $drafts = $this->generate->handle(
                $generation->id,
                $productContext,
                $analysis,
                (int) $generation->count_requested,
                $provider,
                $model,
                $context?->generationPromptVersion ?? $generation->prompt_version,
            );

            $stage = GenerationStage::SavingDrafts;
            $this->lifecycle->enterStage($generation->id, $token, $stage);

            $durationMs = $this->lifecycle->complete($generation->id, $token, $startedAt, function () use ($generation, $drafts): void {
                foreach ($drafts as $draft) {
                    AiGeneratedReview::query()->create([
                        'generation_id' => $generation->id,
                        'product_id' => $generation->product_id,
                        'name' => $draft->name,
                        'rating' => $draft->rating,
                        'title' => $draft->title,
                        'body' => $draft->body,
                        'status' => DraftStatus::Pending->value,
                    ]);
                }
            });

            $this->safeLog(fn () => $this->log->success($generation->id, GenerationLogType::ProcessingCompleted, [
                'stage' => GenerationStage::Finished->value,
            ], [
                'drafts' => count($drafts),
                'duration_ms' => $durationMs,
            ]));
        } catch (GenerationOwnershipLostException $e) {
            Log::warning('ProductReviewAI worker lost ownership of a generation; result discarded', [
                'generation_id' => $generation->id,
                'stage' => $stage->value,
            ]);
        } catch (Throwable $e) {
            $this->recordFailure($generation->id, $token, $e, $stage, $startedAt);
        }
    }

    /**
     * @return array{0: ReviewSourceInterface, 1: ExternalProductMapping}
     */
    private function resolveSourceAndMapping(AiReviewGeneration $generation, ?GenerationRequestContext $context): array
    {
        if ($context !== null) {
            [$source, $adapter] = $this->sources->resolve($context->sourceCode);

            if ((int) $source->id !== $context->sourceId) {
                throw new SourceNotConfiguredException(
                    "The review source [{$context->sourceCode}] this generation was requested with no longer exists."
                );
            }

            $mapping = ExternalProductMapping::query()
                ->whereKey($context->mappingId)
                ->where('product_id', $generation->product_id)
                ->where('source_id', $source->id)
                ->first();

            if (! $mapping instanceof ExternalProductMapping || (string) $mapping->external_id !== $context->externalId) {
                throw new MappingNotFoundException(
                    'The external product mapping this generation was requested with no longer exists or has changed.'
                );
            }

            return [$adapter, $mapping];
        }

        // Legacy run (no snapshot): the original behaviour.
        $source = $generation->source;

        if (! $source instanceof ReviewSource) {
            throw new SourceNotConfiguredException('The generation has no review source.');
        }

        [$source, $adapter] = $this->sources->resolve((string) $source->code);

        $mapping = ExternalProductMapping::query()
            ->where('product_id', $generation->product_id)
            ->where('source_id', $source->id)
            ->first();

        if (! $mapping instanceof ExternalProductMapping) {
            throw new MappingNotFoundException('No external product is mapped for this source.');
        }

        return [$adapter, $mapping];
    }

    private function recordFailure(int $generationId, string $token, Throwable $e, GenerationStage $stage, CarbonInterface $startedAt): void
    {
        $code = $this->classifier->classify($e, $stage);
        $reason = $this->classifier->reasonFor($e, $code);

        // Full technical detail goes to the protected server log only.
        Log::warning('ProductReviewAI generation failed', [
            'generation_id' => $generationId,
            'stage' => $stage->value,
            'failure_code' => $code->value,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        try {
            $recorded = $this->lifecycle->fail($generationId, $token, $code, $reason, $stage, $startedAt);
        } catch (Throwable $recordError) {
            Log::error('ProductReviewAI could not record a generation failure', [
                'generation_id' => $generationId,
                'failure_code' => $code->value,
                'error' => $recordError->getMessage(),
            ]);

            // Let the job's failed() handler try again.
            throw $recordError;
        }

        if ($recorded) {
            $this->safeLog(fn () => $this->log->failure($generationId, GenerationLogType::ProcessingFailed, $reason, [
                'stage' => $stage->value,
                'failure_code' => $code->value,
            ]));
        }
    }

    private function logSkippedDelivery(int $generationId): void
    {
        $status = AiReviewGeneration::query()->whereKey($generationId)->value('status');
        $status = $status instanceof GenerationStatus ? $status->value : (string) $status;

        Log::info('ProductReviewAI ignored a delivery for a generation that is not pending', [
            'generation_id' => $generationId,
            'status' => $status,
        ]);

        $this->safeLog(fn () => $this->log->success($generationId, GenerationLogType::ProcessingSkipped, [
            'current_status' => $status,
            'reason' => 'not_pending',
        ]));
    }

    /**
     * Audit-log writes after a state transition must never undo or mask it.
     */
    private function safeLog(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            Log::error('ProductReviewAI could not write a generation log entry', ['error' => $e->getMessage()]);
        }
    }
}
