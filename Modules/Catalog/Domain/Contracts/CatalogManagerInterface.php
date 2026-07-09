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

    /** Resolves a single published product by its public UUID with full gallery and variants. */
    public function findProduct(string $uuid): ?ProductDTO;

    public function findProductBySlug(string $slug): ?ProductDTO;

    /** Fetch a product by its public UUID regardless of publish status (admin use). */
    public function findProductAdmin(string $uuid): ?ProductDTO;

    /** @return LengthAwarePaginator<ProductDTO> */
    public function getProductsByCategory(int $categoryId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Fetch all published products with optional filters.
     *
     * Supported keys in $filters:
     *   - category_id  (int)    — exact match on category_id
     *   - min_price    (int)    — default variant base_price >= value
     *   - max_price    (int)    — default variant base_price <= value
     *   - search       (string) — LIKE %value% on title OR description
     *
     * @return LengthAwarePaginator<ProductDTO>
     */
    public function getProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Fetch products in every status (admin listing).
     *
     * Supported keys in $filters:
     *   - status       (string) — exact match on status (draft|published)
     *   - category_id  (int)    — exact match on category_id
     *   - min_price    (int)    — default variant base_price >= value
     *   - max_price    (int)    — default variant base_price <= value
     *   - search       (string) — LIKE %value% on title, description, slug, or variant SKU
     *
     * @return LengthAwarePaginator<ProductDTO>
     */
    public function getProductsAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function createProduct(array $data): ProductDTO;

    public function addProductImage(int $productId, int $mediaId, int $sortOrder = 0): ProductImageDTO;

    public function removeProductImage(int $imageId): void;

    public function updateProduct(string $uuid, array $data): ProductDTO;

    public function deleteProduct(string $uuid): void;

    // ── Product Variants ──────────────────────────────────────────────────────

    public function findVariant(int $variantId): ?ProductVariantDTO;

    public function findVariantBySku(string $sku): ?ProductVariantDTO;

    public function createProductVariant(int $productId, array $data): ProductVariantDTO;

    /** Update arbitrary variant fields; enforces is_default single-true invariant. */
    public function updateProductVariant(int $variantId, array $data): ProductVariantDTO;

    public function deleteProductVariant(int $variantId): void;
}
