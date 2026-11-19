<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\GenerationLogDTO;

/**
 * One sanitized execution-log entry. `diagnostics` is present only when the
 * caller asked for it and holds product-review-ai.view-logs.
 *
 * @mixin GenerationLogDTO
 */
class GenerationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GenerationLogDTO $dto */
        $dto = $this->resource;

        $data = [
            'id' => $dto->id,
            'generation_id' => $dto->generationId,
            'type' => $dto->type,
            'status' => $dto->status,
            'stage' => $dto->stage,
            'error' => $dto->error,
            'request_summary' => $dto->requestSummary,
            'response_summary' => $dto->responseSummary,
            'omitted_fields' => $dto->omittedFields,
            'created_at' => $dto->createdAt?->copy()->utc()->toISOString(),
        ];

        if ($dto->diagnostics !== null) {
            $data['diagnostics'] = $dto->diagnostics;
        }

        return $data;
    }
}
