<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Promotion\Domain\DTOs\CampaignDTO;

/**
 * Storefront view of a campaign.
 *
 * Consumes Promotion's CampaignDTO across the module wall — a DTO, never a model.
 * Deliberately narrower than the admin CampaignResource: `is_active`,
 * `show_on_landing`, `sort_order`, and the linked-discount count are operational
 * detail a shopper has no use for, and publishing them would leak merchandising
 * strategy.
 *
 * @mixin CampaignDTO
 */
class PublicCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CampaignDTO $dto */
        $dto = $this->resource;

        return [
            // The slug is the public handle used to fetch this campaign's products.
            'slug' => $dto->slug,
            'name' => $dto->name,
            'description' => $dto->description,
            'starts_at' => $dto->startsAt?->toISOString(),
            'ends_at' => $dto->endsAt?->toISOString(),
        ];
    }
}
