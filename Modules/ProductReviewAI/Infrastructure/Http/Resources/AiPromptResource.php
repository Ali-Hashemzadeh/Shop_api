<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductReviewAI\Domain\DTOs\AiPromptDTO;

/**
 * @mixin AiPromptDTO
 */
class AiPromptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AiPromptDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'model' => $dto->model,
            'version' => $dto->version,
            'system_prompt' => $dto->systemPrompt,
            'user_prompt' => $dto->userPrompt,
            'active' => $dto->active,
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
        ];
    }
}
