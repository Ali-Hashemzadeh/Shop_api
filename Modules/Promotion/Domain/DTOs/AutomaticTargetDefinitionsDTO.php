<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

/**
 * The raw Catalog ids currently reached by active automatic discounts, grouped by
 * target type.
 *
 * Published so Catalog can answer "which products are on sale?" with its *own*
 * SQL — the `has_discount` filter, or a campaign's product list — instead of
 * paginating first and then filtering in PHP, which would corrupt page counts.
 * Category ids are given exactly as targeted; expanding them to descendants is
 * Catalog's job, because Catalog owns the hierarchy.
 */
class AutomaticTargetDefinitionsDTO
{
    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $variantIds
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $brandIds
     */
    public function __construct(
        public readonly array $productIds = [],
        public readonly array $variantIds = [],
        public readonly array $categoryIds = [],
        public readonly array $brandIds = [],
    ) {}

    /** True when no active automatic discount targets anything at all. */
    public function isEmpty(): bool
    {
        return $this->productIds === []
            && $this->variantIds === []
            && $this->categoryIds === []
            && $this->brandIds === [];
    }
}
