<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class CreateProductVariantAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface $media,
    ) {}

    /**
     * Create a purchasable variant for a product.
     *
     * Cents Rule: base_price and compare_at_price MUST be integers. A non-integer
     * value throws immediately rather than silently truncating.
     *
     * Default invariant: if is_default is true, any existing default variant for
     * this product is unset first, guaranteeing exactly one default per product.
     */
    public function handle(int $productId, array $data, ?UploadedFile $variantImage = null): ProductVariantDTO
    {
        $this->assertCentsRule($data['base_price'], 'base_price');

        if (isset($data['compare_at_price'])) {
            $this->assertCentsRule($data['compare_at_price'], 'compare_at_price');
        }

        return DB::transaction(function () use ($productId, $data, $variantImage): ProductVariantDTO {
            $existingCount = ProductVariant::where('product_id', $productId)->lockForUpdate()->count();
            $sku = 'bdp' . $productId . '-' . ($existingCount + 1) . '-' . random_int(10000, 99999);

            $mediaId = $this->resolveMediaId($variantImage, $data['media_id'] ?? null);
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                // Unset any existing default for this product before marking the new one
                ProductVariant::query()
                    ->where('product_id', $productId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return $this->catalog->createProductVariant($productId, [
                'sku' => $sku,
                'type' => $data['type'],
                'is_default' => $isDefault,
                'base_price' => (int) $data['base_price'],
                'compare_at_price' => isset($data['compare_at_price']) ? (int) $data['compare_at_price'] : null,
                'media_id' => $mediaId,
                'attributes' => $data['attributes'] ?? null,
            ]);
        });
    }

    private function resolveMediaId(?UploadedFile $upload, mixed $existingId): ?int
    {
        if ($upload !== null) {
            return $this->media->upload($upload, 'products/variants')->id;
        }

        return $existingId !== null ? (int) $existingId : null;
    }

    private function assertCentsRule(mixed $value, string $field): void
    {
        if (! is_int($value)) {
            throw new \InvalidArgumentException(
                "Cents Rule violation: {$field} must be a raw integer (cents). Received type: ".gettype($value)
            );
        }
    }
}
