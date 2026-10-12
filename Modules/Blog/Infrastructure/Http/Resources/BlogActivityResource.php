<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogActivityDTO;

/** @mixin BlogActivityDTO */
class BlogActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogActivityDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'action' => $dto->action,
            'actor_user_id' => $dto->actorUserId,
            'metadata' => $dto->metadata,
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
