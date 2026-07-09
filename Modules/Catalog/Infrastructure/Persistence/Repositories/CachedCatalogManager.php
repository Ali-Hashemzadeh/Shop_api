<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\CategoryDTO;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\DTOs\ProductImageDTO;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;

class CachedCatalogManager implements CatalogManagerInterface
{
    public function __construct(
        private readonly CatalogManagerInterface $inner,
        private readonly CacheRepository $cache,
        private readonly int $ttl,
    ) {}

    // ── Categories ────────────────────────────────────────────────────────────

    public function findCategory(int $id): ?CategoryDTO
    {
        return $this->cache->remember(
            "catalog:category:{$id}",
            $this->ttl,
            fn () => $this->inner->findCategory($id),
        );
    }

    public function getActiveRootCategories(int $perPage = 15): LengthAwarePaginator
    {
        $page = request()->integer('page', 1);
        $version = $this->categoryVersion();
        $key = "catalog:categories:v{$version}:roots:{$page}:{$perPage}";

        return $this->cache->remember($key, $this->ttl, fn () => $this->inner->getActiveRootCategories($perPage));
    }

    public function createCategory(array $data): CategoryDTO
    {
        $result = $this->inner->createCategory($data);
        $this->bumpCategoryVersion();

        return $result;
    }

    public function updateCategory(int $id, array $data): CategoryDTO
    {
        $result = $this->inner->updateCategory($id, $data);
        $this->cache->forget("catalog:category:{$id}");
        $this->bumpCategoryVersion();

        return $result;
    }

    public function deleteCategory(int $id): void
    {
        $this->inner->deleteCategory($id);
        $this->cache->forget("catalog:category:{$id}");
        $this->bumpCategoryVersion();
    }

    // ── Products ──────────────────────────────────────────────────────────────

    public function findProduct(string $uuid): ?ProductDTO
    {
        return $this->cache->remember(
            "catalog:product:{$uuid}",
            $this->ttl,
            fn () => $this->inner->findProduct($uuid),
        );
    }

    public function findProductBySlug(string $slug): ?ProductDTO
    {
        return $this->cache->remember(
            "catalog:product:slug:{$slug}",
            $this->ttl,
            fn () => $this->inner->findProductBySlug($slug),
        );
    }

    public function findProductAdmin(string $uuid): ?ProductDTO
    {
        return $this->inner->findProductAdmin($uuid);
    }

    public function getProductsByCategory(int $categoryId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->getProducts(array_merge($filters, ['category_id' => $categoryId]), $perPage);
    }

    public function getProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $page = request()->integer('page', 1);
        $version = $this->productsVersion();
        $hash = md5((string) json_encode(['filters' => $filters, 'page' => $page, 'per_page' => $perPage]));
        $key = "catalog:products:v{$version}:{$hash}";

        return $this->cache->remember($key, $this->ttl, fn () => $this->inner->getProducts($filters, $perPage));
    }

    public function getProductsAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        // Admin listings surface drafts and must never serve stale state — bypass the cache,
        // mirroring findProductAdmin().
        return $this->inner->getProductsAdmin($filters, $perPage);
    }

    public function createProduct(array $data): ProductDTO
    {
        $result = $this->inner->createProduct($data);
        $this->bumpProductsVersion();

        return $result;
    }

    public function addProductImage(int $productId, int $mediaId, int $sortOrder = 0): ProductImageDTO
    {
        $result = $this->inner->addProductImage($productId, $mediaId, $sortOrder);
        $this->forgetProductByInternalId($productId);
        $this->bumpProductsVersion();

        return $result;
    }

    public function removeProductImage(int $imageId): void
    {
        // Resolve the owning product's UUID before deletion so we can invalidate its cache entry.
        $uuid = DB::table('product_images')
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->where('product_images.id', $imageId)
            ->value('products.uuid');

        $this->inner->removeProductImage($imageId);

        if ($uuid) {
            $this->cache->forget("catalog:product:{$uuid}");
        }
        $this->bumpProductsVersion();
    }

    public function updateProduct(string $uuid, array $data): ProductDTO
    {
        // Grab old cached DTO (zero DB cost if warm) to invalidate the previous slug key.
        $old = $this->cache->get("catalog:product:{$uuid}");

        $result = $this->inner->updateProduct($uuid, $data);

        $this->cache->forget("catalog:product:{$uuid}");
        if ($old instanceof ProductDTO) {
            $this->cache->forget("catalog:product:slug:{$old->slug}");
        }
        $this->cache->forget("catalog:product:slug:{$result->slug}");
        $this->bumpProductsVersion();

        return $result;
    }

    public function deleteProduct(string $uuid): void
    {
        $old = $this->cache->get("catalog:product:{$uuid}");

        $this->inner->deleteProduct($uuid);

        $this->cache->forget("catalog:product:{$uuid}");
        if ($old instanceof ProductDTO) {
            $this->cache->forget("catalog:product:slug:{$old->slug}");
        }
        $this->bumpProductsVersion();
    }

    // ── Product Variants ──────────────────────────────────────────────────────

    public function findVariant(int $variantId): ?ProductVariantDTO
    {
        return $this->cache->remember(
            "catalog:variant:{$variantId}",
            $this->ttl,
            fn () => $this->inner->findVariant($variantId),
        );
    }

    public function findVariantBySku(string $sku): ?ProductVariantDTO
    {
        return $this->cache->remember(
            "catalog:variant:sku:{$sku}",
            $this->ttl,
            fn () => $this->inner->findVariantBySku($sku),
        );
    }

    public function createProductVariant(int $productId, array $data): ProductVariantDTO
    {
        $result = $this->inner->createProductVariant($productId, $data);
        $this->forgetProductByInternalId($productId);
        $this->bumpProductsVersion();

        return $result;
    }

    public function updateProductVariant(int $variantId, array $data): ProductVariantDTO
    {
        $old = $this->cache->get("catalog:variant:{$variantId}");
        $uuid = $this->productUuidByVariant($variantId);

        $result = $this->inner->updateProductVariant($variantId, $data);

        $this->cache->forget("catalog:variant:{$variantId}");
        if ($old instanceof ProductVariantDTO) {
            $this->cache->forget("catalog:variant:sku:{$old->sku}");
        }
        $this->cache->forget("catalog:variant:sku:{$result->sku}");
        if ($uuid) {
            $this->cache->forget("catalog:product:{$uuid}");
        }
        $this->bumpProductsVersion();

        return $result;
    }

    public function deleteProductVariant(int $variantId): void
    {
        $old = $this->cache->get("catalog:variant:{$variantId}");
        $uuid = $this->productUuidByVariant($variantId);

        $this->inner->deleteProductVariant($variantId);

        $this->cache->forget("catalog:variant:{$variantId}");
        if ($old instanceof ProductVariantDTO) {
            $this->cache->forget("catalog:variant:sku:{$old->sku}");
        }
        if ($uuid) {
            $this->cache->forget("catalog:product:{$uuid}");
        }
        $this->bumpProductsVersion();
    }

    // ── Cache-key helpers ─────────────────────────────────────────────────────

    /**
     * The product read-cache is keyed by public UUID, but the FK-insert helpers
     * (add image / create variant) address the product by its internal integer id.
     * Resolve the UUID so the correct cache entry is invalidated.
     */
    private function forgetProductByInternalId(int $productId): void
    {
        $uuid = DB::table('products')->where('id', $productId)->value('uuid');

        if ($uuid) {
            $this->cache->forget("catalog:product:{$uuid}");
        }
    }

    private function productUuidByVariant(int $variantId): ?string
    {
        return DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_variants.id', $variantId)
            ->value('products.uuid');
    }

    // ── Version helpers ───────────────────────────────────────────────────────

    private function categoryVersion(): int
    {
        return (int) $this->cache->get('catalog.categories.version', 0);
    }

    private function bumpCategoryVersion(): void
    {
        $this->cache->increment('catalog.categories.version');
    }

    private function productsVersion(): int
    {
        return (int) $this->cache->get('catalog.products.version', 0);
    }

    private function bumpProductsVersion(): void
    {
        $this->cache->increment('catalog.products.version');
    }
}
