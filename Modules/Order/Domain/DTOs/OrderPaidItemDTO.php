<?php

declare(strict_types=1);

namespace Modules\Order\Domain\DTOs;

/**
 * Immutable carrier for a single purchased item in OrderPaidEvent.
 *
 * Carries all primitive identifiers and financial integers so downstream modules
 * (such as Analytics) never need to query Order, Catalog, or Promotion tables.
 */
class OrderPaidItemDTO
{
    /**
     * @param  list<int>  $categoryIds  The item's category and all its ancestor categories.
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $variantId,
        public readonly int $quantity,
        public readonly int $unitPrice,
        public readonly int $discountAmount = 0,
        public readonly array $categoryIds = [],
        public readonly ?int $discountId = null,
        public readonly int $regularUnitPrice = 0,
        public readonly ?string $sku = null,
        public readonly ?string $productTitle = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            productId: (int) ($data['product_id'] ?? $data['productId'] ?? 0),
            variantId: (int) ($data['variant_id'] ?? $data['variantId'] ?? 0),
            quantity: (int) ($data['quantity'] ?? 1),
            unitPrice: (int) ($data['unit_price'] ?? $data['unitPrice'] ?? $data['price_per_unit'] ?? 0),
            discountAmount: (int) ($data['discount_amount'] ?? $data['discountAmount'] ?? 0),
            categoryIds: array_values(array_map('intval', $data['category_ids'] ?? $data['categoryIds'] ?? [])),
            discountId: isset($data['discount_id']) ? (int) $data['discount_id'] : (isset($data['discountId']) ? (int) $data['discountId'] : null),
            regularUnitPrice: (int) ($data['regular_unit_price'] ?? $data['regularUnitPrice'] ?? $data['regular_price_per_unit'] ?? 0),
            sku: isset($data['sku']) ? (string) $data['sku'] : null,
            productTitle: isset($data['product_title']) ? (string) $data['product_title'] : (isset($data['productTitle']) ? (string) $data['productTitle'] : null),
        );
    }
}
