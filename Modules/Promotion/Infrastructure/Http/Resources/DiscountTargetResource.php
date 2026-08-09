<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Promotion\Domain\DTOs\DiscountTargetDTO;

/** @mixin DiscountTargetDTO */
class DiscountTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DiscountTargetDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'target_type' => $dto->targetType->value,
            // Reported exactly as stored. Whether the Catalog row still exists is
            // not checked here — the admin UI resolves names via Catalog's own APIs.
            'target_id' => $dto->targetId,
        ];
    }
}
