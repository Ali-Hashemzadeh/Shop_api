<?php

namespace Modules\Catalog\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Catalog\Domain\DTOs\BrandDTO;
use Modules\Catalog\Domain\DTOs\CategoryDTO;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\DTOs\ProductImageDTO;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;

interface CatalogManagerInterface
{
    // ── Categories ────────────────────────────────────────────────────────────

    public function findCategory(int $id): ?CategoryDTO;

    /**
     * Active categories for the storefront.
     *
     * Supported keys in $filters:
     *   - search (string) — an exact `bdc-XXXXXX` public code (case-insensitive,
     *     matched on categories.public_code at any depth), otherwise LIKE on
     *     name or slug. Without a search term the result is the root menu only.
     *
     * @return LengthAwarePaginator<CategoryDTO>
     */
    public function getActiveRootCategories(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function createCategory(array $data): CategoryDTO;

    public function updateCategory(int $id, array $data): CategoryDTO;

    public function deleteCategory(int $id): void;

    // ── Brands ────────────────────────────────────────────────────────────────

    public function findBrand(int $id): ?BrandDTO;

    /** @return LengthAwarePaginator<BrandDTO> */
    public function getBrands(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function createBrand(array $data): BrandDTO;

    public function updateBrand(int $id, array $data): BrandDTO;

    public function deleteBrand(int $id): void;

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
     *   - category_id  (int)    — matches the selected category and all of its descendants recursively (ancestors excluded)
     *   - brand_id     (int)    — exact match on brand_id
     *   - min_price    (int)    — default variant base_price >= value
     *   - max_price    (int)    — default variant base_price <= value
     *   - search       (string) — an exact `bdp-XXXXXX` product code (matched on
     *                             products.uuid) or `bdv-XXXXXX` variant code
     *                             (matched on product_variants.sku, returning the
     *                             owning product), both case-insensitive and
     *                             whole-string; anything else is LIKE %value% on
     *                             title, description, OR brand name
     *   - sort         (string) — cheapest | most_expensive (default variant base_price) | most_sold (sales_count desc)
     *
     * @return LengthAwarePaginator<ProductDTO>
     */
    public function getProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Fetch products in every status (admin listing).
     *
     * Supported keys in $filters:
     *   - status       (string) — exact match on status (draft|published)
     *   - category_id  (int)    — matches the selected category and all of its descendants recursively (ancestors excluded)
     *   - brand_id     (int)    — exact match on brand_id
     *   - min_price    (int)    — default variant base_price >= value
     *   - max_price    (int)    — default variant base_price <= value
     *   - search       (string) — LIKE %value% on title, description, slug, variant SKU, or brand name
     *   - sort         (string) — cheapest | most_expensive (default variant base_price) | most_sold (sales_count desc)
     *
     * @return LengthAwarePaginator<ProductDTO>
     */
    public function getProductsAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Published products merchandised by a campaign, with live automatic pricing.
     *
     * Catalog resolves the product list itself from the target ids Promotion
     * publishes — variant targets resolve to their owning product, category targets
     * expand to descendants, and duplicates collapse. Returns null when the campaign
     * does not exist or is not currently visible; an empty page when the campaign is
     * live but none of its rules currently match anything.
     *
     * Note this decides only *membership*. Each product still displays whatever
     * discount wins globally, which may be a stronger rule from outside the campaign.
     *
     * @param  array<string, mixed>  $filters  Same keys as getProducts().
     * @return LengthAwarePaginator<ProductDTO>|null
     */
    public function getCampaignProducts(string $slug, array $filters = [], int $perPage = 15): ?LengthAwarePaginator;

    public function createProduct(array $data): ProductDTO;

    public function addProductImage(int $productId, int $mediaId, int $sortOrder = 0): ProductImageDTO;

    public function removeProductImage(int $imageId): void;

    public function updateProduct(string $uuid, array $data): ProductDTO;

    public function deleteProduct(string $uuid): void;

    /**
     * Replace the denormalized best-seller counters from an authoritative,
     * absolute per-SKU tally (owned by the Order module).
     *
     * Each SKU is resolved to its owning product and the totals are summed per
     * product (multiple variants of one product accumulate). Products absent
     * from the tally are reset to 0. SKUs unknown to Catalog are ignored.
     *
     * @param  array<string, int>  $skuTotals  sku => total units sold
     */
    public function syncSalesCounts(array $skuTotals): void;

    // ── Product Variants ──────────────────────────────────────────────────────

    public function findVariant(int $variantId): ?ProductVariantDTO;

    public function findVariantBySku(string $sku): ?ProductVariantDTO;

    /** @return array<string, ProductVariantDTO> Variants keyed by SKU. */
    public function getVariantsBySkus(array $skus): array;

    public function createProductVariant(int $productId, array $data): ProductVariantDTO;

    /** Update arbitrary variant fields; enforces is_default single-true invariant. */
    public function updateProductVariant(int $variantId, array $data): ProductVariantDTO;

    public function deleteProductVariant(int $variantId): void;
}
