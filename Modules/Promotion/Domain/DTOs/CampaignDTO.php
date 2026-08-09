<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

use Carbon\Carbon;
use Modules\Promotion\Domain\Models\Campaign;

/**
 * Customer-safe campaign metadata.
 *
 * Carries only what a storefront section needs to render. The linked discounts and
 * their configuration are deliberately absent: a campaign explains *why* products
 * are grouped, never what they cost.
 */
class CampaignDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?Carbon $startsAt,
        public readonly ?Carbon $endsAt,
        public readonly bool $isActive,
        public readonly bool $showOnLanding,
        public readonly int $sortOrder,
        /** Number of linked automatic discounts; admin-facing detail. */
        public readonly ?int $discountCount = null,
    ) {}

    public static function fromModel(Campaign $campaign, ?int $discountCount = null): self
    {
        return new self(
            id: $campaign->id,
            name: $campaign->name,
            slug: $campaign->slug,
            description: $campaign->description,
            startsAt: $campaign->starts_at,
            endsAt: $campaign->ends_at,
            isActive: $campaign->is_active,
            showOnLanding: $campaign->show_on_landing,
            sortOrder: $campaign->sort_order,
            discountCount: $discountCount,
        );
    }
}
