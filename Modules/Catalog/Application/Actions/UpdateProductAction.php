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

    public function handle(int $id, array $data): ProductDTO
    {
        return DB::transaction(function () use ($id, $data): ProductDTO {
            $variantsData = $data['variants'] ?? null;
            $productData = collect($data)->except(['variants'])->all();

            $productDto = $this->catalog->updateProduct($id, $productData);

            if ($variantsData !== null) {
                $existingBySku = collect($productDto->variants)->keyBy('sku');

                foreach ($variantsData as $variantData) {
                    $existing = $existingBySku->get($variantData['sku']);

                    if ($existing !== null) {
                        $this->catalog->updateProductVariant($existing->id, $variantData);
                    } else {
                        $this->catalog->createProductVariant($id, $variantData);
                    }
                }
            }

            return $this->catalog->findProductAdmin($id)
                ?? throw new \RuntimeException("Product #{$id} could not be loaded after update.");
        });
    }
}
