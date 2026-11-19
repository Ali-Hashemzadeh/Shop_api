<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Application\Support\GenerationDispatcher;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Application\Support\PromptResolver;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestContext;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\GenerationRetryConflictException;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;

/**
 * Admin-triggered retry of a FAILED run. It never resets or mutates the
 * original (its status, failure and logs stay exactly as they were): it creates
 * a NEW generation linked by `retry_of_id`, copying the original's immutable
 * request snapshot, and queues it.
 *
 * Rules (all 409 GenerationRetryConflictException):
 *  - only `failed` runs can be retried;
 *  - a run can be retried once — the chain is linear (A → B → C); to try again
 *    after a failed retry, retry the latest attempt. Enforced by the UNIQUE
 *    index on retry_of_id, so two concurrent requests can never both create a
 *    retry (the loser hits the index and gets 409), on every database driver;
 *  - the original request must be reproducible exactly: a snapshot must exist
 *    (historical runs have none), and its source, mapping/external id, prompt
 *    versions and AI provider must still be available. Otherwise the retry is
 *    refused instead of silently running with today's configuration.
 *
 * Dispatch happens after commit; a failed push marks the NEW run failed and
 * surfaces a 503 (GenerationDispatchException) — never an orphaned pending row.
 */
class RetryGenerationAction
{
    public function __construct(
        private readonly PromptResolver $prompts,
        private readonly GenerationLogWriter $log,
        private readonly GenerationDispatcher $dispatcher,
    ) {}

    public function handle(int $generationId, int $adminUserId): AiReviewGeneration
    {
        try {
            [$retry, $context] = DB::transaction(function () use ($generationId, $adminUserId): array {
                /** @var AiReviewGeneration|null $original */
                $original = AiReviewGeneration::query()->whereKey($generationId)->lockForUpdate()->first();

                if ($original === null) {
                    abort(404, 'Generation not found.');
                }

                if ($original->status !== GenerationStatus::Failed) {
                    throw new GenerationRetryConflictException(
                        'Only failed generations can be retried.',
                        GenerationRetryConflictException::NOT_FAILED,
                        ['status' => $original->status?->value],
                    );
                }

                $existing = AiReviewGeneration::query()
                    ->where('retry_of_id', $original->id)
                    ->first(['id', 'status']);

                if ($existing !== null) {
                    throw $this->alreadyRetried($existing);
                }

                $context = $this->reproducibleContext($original);

                /** @var AiReviewGeneration $retry */
                $retry = AiReviewGeneration::query()->create([
                    'product_id' => $original->product_id,
                    'source_id' => $context->sourceId,
                    'model' => $context->aiModel,
                    'count_requested' => $context->count,
                    'status' => GenerationStatus::Pending->value,
                    'current_stage' => GenerationStage::Queued->value,
                    'prompt_name' => $original->prompt_name,
                    'prompt_version' => $original->prompt_version,
                    'created_by' => $adminUserId,
                    'retry_of_id' => $original->id,
                    'request_context' => $context->toArray(),
                ]);

                $this->log->success($original->id, GenerationLogType::RetryRequested, [
                    'retry_generation_id' => $retry->id,
                    'requested_by' => $adminUserId,
                ]);

                return [$retry, $context];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the retry between our check and insert.
            $existing = AiReviewGeneration::query()->where('retry_of_id', $generationId)->first(['id', 'status']);

            throw $existing !== null
                ? $this->alreadyRetried($existing)
                : new GenerationRetryConflictException(
                    'This generation is already being retried.',
                    GenerationRetryConflictException::ALREADY_RETRIED,
                );
        }

        $this->dispatcher->dispatch($retry->id, $context->productContext());

        return $retry->refresh();
    }

    private function reproducibleContext(AiReviewGeneration $original): GenerationRequestContext
    {
        $context = GenerationRequestContext::fromArray($original->request_context);

        if ($context === null) {
            throw $this->notReproducible('This generation predates request snapshots, so its original configuration cannot be reproduced exactly. Start a new generation for the product instead.');
        }

        $source = ReviewSource::query()->whereKey($context->sourceId)->first();

        if ($source === null || ! $source->is_active || $source->code !== $context->sourceCode) {
            throw $this->notReproducible("The review source [{$context->sourceCode}] used by the original request is no longer available.");
        }

        $mapping = ExternalProductMapping::query()
            ->whereKey($context->mappingId)
            ->where('product_id', $context->productId)
            ->first();

        if ($mapping === null || (string) $mapping->external_id !== $context->externalId) {
            throw $this->notReproducible('The external product mapping used by the original request has changed or been removed.');
        }

        foreach ([
            [$context->analysisPromptName, $context->analysisPromptVersion],
            [$context->generationPromptName, $context->generationPromptVersion],
        ] as [$name, $version]) {
            if ($name === null || $version === null || ! $this->prompts->exists($name, $version)) {
                throw $this->notReproducible('A prompt version used by the original request is not available.');
            }
        }

        if ($context->aiProvider !== (string) config('product_review_ai.ai.provider')) {
            throw $this->notReproducible("The AI provider [{$context->aiProvider}] used by the original request is no longer the configured provider.");
        }

        return $context;
    }

    private function alreadyRetried(AiReviewGeneration $existing): GenerationRetryConflictException
    {
        $status = $existing->status instanceof GenerationStatus ? $existing->status->value : (string) $existing->status;
        $active = in_array($status, [GenerationStatus::Pending->value, GenerationStatus::Processing->value], true);

        return new GenerationRetryConflictException(
            $active
                ? 'A retry of this generation is already in progress.'
                : 'This generation was already retried. Retry the latest attempt instead.',
            GenerationRetryConflictException::ALREADY_RETRIED,
            ['retry_generation_id' => (int) $existing->id, 'retry_status' => $status],
        );
    }

    private function notReproducible(string $message): GenerationRetryConflictException
    {
        return new GenerationRetryConflictException($message, GenerationRetryConflictException::CONTEXT_UNAVAILABLE);
    }
}
