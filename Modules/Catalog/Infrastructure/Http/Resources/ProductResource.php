<?php

namespace Modules\Catalog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Infrastructure\Http\Concerns\WishlistStateKeys;

/** @mixin ProductDTO */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductDTO $dto */
        $dto = $this->resource;

        // User-specific like state. Pre-resolved per page by the controller and
        // stashed on the request (see InteractsWithWishlistState), so listing a
        // page of products never fans out into one like query per product.
        // Defaults to false for guests and for endpoints that do not annotate.
        $likedProductIds = $request->attributes->get(WishlistStateKeys::LIKED_ATTR, []);

        return [
            // Public identifier is the opaque UUID; the internal integer id is not exposed.
            'id' => $dto->uuid,
            // Numeric key for cross-referencing surfaces keyed by integer ids
            // (e.g. reviews' subject_id). Never used in URLs by clients.
            'product_id' => $dto->id,
            'title' => $dto->title,
            'slug' => $dto->slug,
            'description' => $dto->description,
            'features' => $dto->features,
            'status' => $dto->status,
            'sales_count' => $dto->salesCount,
            // Rating summary synced from the Review module (approved + rated rows only).
            'rating_average' => $dto->ratingAverage(),
            'rating_count' => $dto->ratingCount,
            'category_id' => $dto->categoryId,
            'brand_id' => $dto->brandId,
            'primary_image_url' => $dto->primaryImageUrl,
            // Whether the authenticated customer has liked this product.
            'is_liked' => isset($likedProductIds[$dto->id]),
            'images' => ProductImageResource::collection($dto->images),
            'variants' => ProductVariantResource::collection($dto->variants),
        ];
    }
}
