<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Promotion\Domain\DTOs\CampaignDTO;

/**
 * Admin campaign view. The customer-facing shape lives in Catalog
 * (PublicCampaignResource) and deliberately omits the operational flags below.
 *
 * @mixin CampaignDTO
 */
class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CampaignDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'slug' => $dto->slug,
            'description' => $dto->description,
            'starts_at' => $dto->startsAt?->toISOString(),
            'ends_at' => $dto->endsAt?->toISOString(),
            'is_active' => $dto->isActive,
            'show_on_landing' => $dto->showOnLanding,
            'sort_order' => $dto->sortOrder,
            'discount_count' => $dto->discountCount,
        ];
    }
}
