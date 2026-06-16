<?php

namespace Modules\Catalog\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Catalog\Domain\DTOs\CategoryDTO;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\DTOs\ProductImageDTO;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;

interface CatalogManagerInterface
{
    // ── Categories ────────────────────────────────────────────────────────────

    public function findCategory(int $id): ?CategoryDTO;

    /** @return LengthAwarePaginator<CategoryDTO> */
    public function getActiveRootCategories(int $perPage = 15): LengthAwarePaginator;

    public function createCategory(array $data): CategoryDTO;

    public function updateCategory(int $id, array $data): CategoryDTO;

    public function deleteCategory(int $id): void;

    // ── Products ──────────────────────────────────────────────────────────────

    /** Resolves a single published product by ID with full gallery and variants. */
    public function findProduct(int $id): ?ProductDTO;

    public function findProductBySlug(string $slug): ?ProductDTO;

    /** Fetch a product by ID regardless of publish status (admin use). */
    public function findProductAdmin(int $id): ?ProductDTO;

    /** @return LengthAwarePaginator<ProductDTO> */
    public function getProductsByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator;

    public function createProduct(array $data): ProductDTO;

    public function addProductImage(int $productId, int $mediaId, int $sortOrder = 0): ProductImageDTO;

    public function removeProductImage(int $imageId): void;

    public function updateProduct(int $id, array $data): ProductDTO;

    public function deleteProduct(int $id): void;

    // ── Product Variants ──────────────────────────────────────────────────────

    public function findVariant(int $variantId): ?ProductVariantDTO;

    public function findVariantBySku(string $sku): ?ProductVariantDTO;

    public function createProductVariant(int $productId, array $data): ProductVariantDTO;

    /** Update arbitrary variant fields; enforces is_default single-true invariant. */
    public function updateProductVariant(int $variantId, array $data): ProductVariantDTO;

    public function deleteProductVariant(int $variantId): void;
}
