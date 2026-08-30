<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductDTO;

class UpdateProductAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function handle(string $uuid, array $data): ProductDTO
    {
        return DB::transaction(function () use ($uuid, $data): ProductDTO {
            $variantsData = $data['variants'] ?? null;
            $galleryMediaIds = $data['gallery_media_ids'] ?? null;
            $productData = collect($data)->except(['variants', 'gallery_media_ids'])->all();

            $productDto = $this->catalog->updateProduct($uuid, $productData);
            // Variant plumbing (FK, SKU) uses the internal integer id; the UUID is only
            // the public handle. The updated DTO carries both.
            $productId = $productDto->id;

            if ($galleryMediaIds !== null) {
                $attachedMediaIds = [];

                foreach ($productDto->images as $image) {
                    $attachedMediaIds[$image->mediaId] = true;
                }

                foreach ($galleryMediaIds as $sortOrder => $mediaId) {
                    $mediaId = (int) $mediaId;

                    if (isset($attachedMediaIds[$mediaId])) {
                        continue;
                    }

                    $this->catalog->addProductImage($productId, $mediaId, $sortOrder);
                    $attachedMediaIds[$mediaId] = true;
                }
            }

            if ($variantsData !== null) {
                $existingById = collect($productDto->variants)->keyBy('id');

                foreach ($variantsData as $variantData) {
                    $variantId = isset($variantData['id']) ? (int) $variantData['id'] : null;

                    if ($variantId !== null && $existingById->has($variantId)) {
                        // Known variant: updated in place, SKU untouched.
                        $this->catalog->updateProductVariant($variantId, collect($variantData)->except('id')->all());
                    } else {
                        // New variant: Catalog mints the SKU, same as a standalone create.
                        $this->catalog->createProductVariant($productId, collect($variantData)->except('id')->all());
                    }
                }
            }

            return $this->catalog->findProductAdmin($uuid)
                ?? throw new \RuntimeException("Product {$uuid} could not be loaded after update.");
        });
    }
}
