<?php

namespace Modules\Catalog\Domain\Contracts;

use Illuminate\Support\Collection;
use Modules\Catalog\Domain\DTOs\CategoryDTO;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;

interface CatalogManagerInterface
{
    /**
     * Resolve a single category by its ID.
     */
    public function findCategory(int $id): ?CategoryDTO;

    /**
     * Resolve all active top-level categories (parent_id = null).
     *
     * @return Collection<CategoryDTO>
     */
    public function getActiveRootCategories(): Collection;

    /**
     * Resolve a single published product by its ID with full gallery and variants.
     */
    public function findProduct(int $id): ?ProductDTO;

    /**
     * Resolve a single published product by its slug.
     */
    public function findProductBySlug(string $slug): ?ProductDTO;

    /**
     * Resolve a specific variant by its ID — used by Cart/Order to confirm
     * current price in cents before writing a line item.
     */
    public function findVariant(int $variantId): ?ProductVariantDTO;

    /**
     * Resolve a variant by SKU — used by Inventory to look up stock.
     */
    public function findVariantBySku(string $sku): ?ProductVariantDTO;

    /**
     * Return all published products belonging to a category.
     *
     * @return Collection<ProductDTO>
     */
    public function getProductsByCategory(int $categoryId): Collection;

    /**
     * Create a new category and return it as a DTO.
     * Accepts a plain data array (name, slug, parent_id, media_id, is_active).
     */
    public function createCategory(array $data): CategoryDTO;

    /**
     * Update a variant's price in cents. Both parameters must be raw integers
     * (cents), never floats — enforced by the Cents Rule.
     */
    public function updateVariantPrice(int $variantId, int $basePrice, ?int $compareAtPrice = null): ProductVariantDTO;
}
