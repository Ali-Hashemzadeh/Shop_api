<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductDTO;

class CreateProductAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function handle(array $data): ProductDTO
    {
        return DB::transaction(function () use ($data): ProductDTO {
            $product = $this->catalog->createProduct([
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'title' => $data['title'],
                'slug' => $data['slug'] ?? Str::slug($data['title'], '-', null),
                'description' => $data['description'] ?? null,
                'features' => $data['features'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'primary_media_id' => isset($data['primary_media_id']) ? (int) $data['primary_media_id'] : null,
            ]);

            foreach ($data['gallery_media_ids'] ?? [] as $sortOrder => $mediaId) {
                $this->catalog->addProductImage($product->id, (int) $mediaId, $sortOrder);
            }

            // SKUs are minted by Catalog's own variant-creation path — this action
            // never composes one, so nested and standalone creates cannot drift.
            foreach ($data['variants'] ?? [] as $variantData) {
                $this->catalog->createProductVariant($product->id, $variantData);
            }

            return $this->catalog->findProductAdmin($product->uuid)
                ?? throw new \RuntimeException("Product #{$product->id} could not be loaded after creation.");
        });
    }
}
