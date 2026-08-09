<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

use Carbon\Carbon;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\DiscountType;
use Modules\Promotion\Domain\Models\Discount;

/**
 * Admin-facing view of a pricing rule. Never returned on customer endpoints —
 * storefront responses expose only the winning result (AutomaticDiscountResultDTO).
 */
class DiscountDTO
{
    /** @param array<int, DiscountTargetDTO> $targets */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly DiscountTriggerType $triggerType,
        public readonly DiscountScope $scope,
        public readonly DiscountType $discountType,
        public readonly ?int $percentageBps,
        public readonly ?int $fixedAmount,
        public readonly ?int $maxDiscountAmount,
        public readonly ?int $minSubtotal,
        public readonly ?Carbon $startsAt,
        public readonly ?Carbon $endsAt,
        public readonly bool $isActive,
        public readonly int $priority,
        public readonly array $targets,
        public readonly ?Carbon $createdAt = null,
    ) {}

    /** @param array<int, DiscountTargetDTO> $targets */
    public static function fromModel(Discount $discount, array $targets = []): self
    {
        return new self(
            id: $discount->id,
            name: $discount->name,
            description: $discount->description,
            triggerType: $discount->trigger_type,
            scope: $discount->scope,
            discountType: $discount->discount_type,
            percentageBps: $discount->percentage_bps,
            fixedAmount: $discount->fixed_amount,
            maxDiscountAmount: $discount->max_discount_amount,
            minSubtotal: $discount->min_subtotal,
            startsAt: $discount->starts_at,
            endsAt: $discount->ends_at,
            isActive: $discount->is_active,
            priority: $discount->priority,
            targets: $targets,
            createdAt: $discount->created_at,
        );
    }
}
