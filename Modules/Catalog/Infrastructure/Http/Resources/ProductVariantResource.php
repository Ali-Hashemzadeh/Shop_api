<?php

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;
use Modules\Catalog\Infrastructure\Http\Concerns\WishlistStateKeys;

/** @mixin ProductVariantDTO */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductVariantDTO $dto */
        $dto = $this->resource;
        $discount = $dto->automaticDiscount;

        // User-specific "notify me when available" state for this exact SKU.
        // Pre-resolved per page by the controller (see InteractsWithWishlistState);
        // false for guests and for endpoints that do not annotate.
        $subscribedSkus = $request->attributes->get(WishlistStateKeys::SUBSCRIBED_ATTR, []);

        return [
            'id' => $dto->id,
            'sku' => $dto->sku,
            'type' => $dto->type,
            'is_default' => $dto->isDefault,
            // The regular price, always present.
            'base_price' => $dto->basePrice,
            // What the customer actually pays right now. Equal to base_price when no
            // automatic discount applies, so clients can render this field alone and
            // never need to compute a promotional price themselves.
            'effective_price' => $dto->effectivePrice(),
            // The one winning rule (automatic discounts never stack), or null.
            'discount' => $discount === null ? null : [
                'name' => $discount->discountName,
                'type' => $discount->discountType->value,
                'percentage_bps' => $discount->percentageBps,
                'fixed_amount' => $discount->fixedAmount,
                // Actual rial reduction after any cap — base_price − effective_price.
                'amount' => $discount->discountAmount,
            ],
            'max_quantity_per_order' => $dto->maxQuantityPerOrder,
            'attributes' => $dto->attributes,
            'image_url' => $dto->imageUrl,
            // Available units for this variant (physical − reserved), from Inventory.
            'stock' => $dto->availableStock,
            'effective_max_quantity' => $dto->availableStock !== null
                ? min(max(0, $dto->availableStock), $dto->maxQuantityPerOrder ?? PHP_INT_MAX)
                : $dto->maxQuantityPerOrder,
            // Whether the authenticated customer has an active restock alert for this SKU.
            'availability_notification_requested' => isset($subscribedSkus[$dto->sku]),
        ];
    }
}
