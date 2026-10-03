<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\LogStatus;
use Modules\ProductReviewAI\Domain\Models\AiReviewGenerationLog;

/**
 * The single writer for the generation debugging ledger. Logging is total, not
 * failure-only: callers record both the request and the response of every
 * external/AI call and every moderation decision.
 */
class GenerationLogWriter
{
    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     */
    public function success(
        int $generationId,
        GenerationLogType $type,
        ?array $request = null,
        ?array $response = null,
    ): AiReviewGenerationLog {
        return $this->write($generationId, $type, LogStatus::Success, $request, $response, null);
    }

    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     */
    public function failure(
        int $generationId,
        GenerationLogType $type,
        string $error,
        ?array $request = null,
        ?array $response = null,
    ): AiReviewGenerationLog {
        return $this->write($generationId, $type, LogStatus::Failed, $request, $response, $error);
    }

    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     */
    private function write(
        int $generationId,
        GenerationLogType $type,
        LogStatus $status,
        ?array $request,
        ?array $response,
        ?string $error,
    ): AiReviewGenerationLog {
        return AiReviewGenerationLog::query()->create([
            'generation_id' => $generationId,
            'type' => $type->value,
            'request' => $request,
            'response' => $response,
            'status' => $status->value,
            'error' => $error,
        ]);
    }
}
