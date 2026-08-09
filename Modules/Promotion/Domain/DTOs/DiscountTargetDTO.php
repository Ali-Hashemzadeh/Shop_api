<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Models\DiscountTarget;

/**
 * One loose reference from a discount into Catalog.
 *
 * `targetId` is reported exactly as stored. Promotion never checks whether the
 * referenced product / variant / category / brand still exists — doing so would
 * require calling Catalog, which already depends on Promotion for pricing.
 */
class DiscountTargetDTO
{
    public function __construct(
        public readonly int $id,
        public readonly DiscountTargetType $targetType,
        public readonly int $targetId,
    ) {}

    public static function fromModel(DiscountTarget $target): self
    {
        return new self(
            id: $target->id,
            targetType: $target->target_type,
            targetId: $target->target_id,
        );
    }
}
