<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

use Carbon\Carbon;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;

/**
 * Immutable view of one versioned prompt template.
 */
class AiPromptDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $model,
        public readonly int $version,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly bool $active,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
    ) {}

    public static function fromModel(AiPrompt $p): self
    {
        return new self(
            id: (int) $p->id,
            name: (string) $p->name,
            model: (string) $p->model,
            version: (int) $p->version,
            systemPrompt: (string) $p->system_prompt,
            userPrompt: (string) $p->user_prompt,
            active: (bool) $p->active,
            createdAt: Carbon::parse($p->created_at),
            updatedAt: Carbon::parse($p->updated_at),
        );
    }
}
