<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\Models\ProductVariant;

class UpdateProductAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function handle(string $uuid, array $data): ProductDTO
    {
        return DB::transaction(function () use ($uuid, $data): ProductDTO {
            $variantsData = $data['variants'] ?? null;
            $productData = collect($data)->except(['variants'])->all();

            $productDto = $this->catalog->updateProduct($uuid, $productData);
            // Variant plumbing (FK, SKU) uses the internal integer id; the UUID is only
            // the public handle. The updated DTO carries both.
            $productId = $productDto->id;

            if ($variantsData !== null) {
                $existingById = collect($productDto->variants)->keyBy('id');
                $existingCount = ProductVariant::where('product_id', $productId)->lockForUpdate()->count();
                $newOffset = 0;

                foreach ($variantsData as $variantData) {
                    $variantId = isset($variantData['id']) ? (int) $variantData['id'] : null;

                    if ($variantId !== null && $existingById->has($variantId)) {
                        $this->catalog->updateProductVariant($variantId, collect($variantData)->except('id')->all());
                    } else {
                        $sku = 'bdp'.$productId.'-v'.($existingCount + $newOffset + 1);
                        $newOffset++;
                        $this->catalog->createProductVariant($productId, array_merge(
                            collect($variantData)->except('id')->all(),
                            ['sku' => $sku]
                        ));
                    }
                }
            }

            return $this->catalog->findProductAdmin($uuid)
                ?? throw new \RuntimeException("Product {$uuid} could not be loaded after update.");
        });
    }
}
