<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogSubscriptionDTO;

/** @mixin BlogSubscriptionDTO */
class BlogSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogSubscriptionDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'subject_type' => $dto->subjectType,
            'subject_id' => $dto->subjectId,
            'subject' => $dto->subjectName === null ? null : [
                'name' => $dto->subjectName,
                'slug' => $dto->subjectSlug,
            ],
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
