<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\BrandDTO;
use Modules\Catalog\Domain\DTOs\CategoryDTO;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\DTOs\ProductImageDTO;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;
use Modules\Catalog\Domain\Models\Brand;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductImage;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;

class EloquentCatalogManager implements CatalogManagerInterface
{
    public function __construct(
        private readonly MediaManagerInterface $media,
        private readonly InventoryManagerInterface $inventory,
    ) {}

    // ── Categories ────────────────────────────────────────────────────────────

    public function findCategory(int $id): ?CategoryDTO
    {
        $category = Category::with([
            'parent.parent.parent.parent.parent',
            'children.children.children.children.children',
        ])->find($id);

        if ($category === null) {
            return null;
        }

        $mediaMap = $this->buildMediaMap(
            array_values(array_unique(array_filter($this->collectCategoryMediaIds($category))))
        );

        return $this->buildCategoryDto($category, $mediaMap);
    }

    public function getActiveRootCategories(int $perPage = 15): LengthAwarePaginator
    {
        $paginator = Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with('children.children.children.children.children')
            ->paginate($perPage);

        $allMediaIds = $paginator->getCollection()
            ->flatMap(fn (Category $cat) => $this->collectCategoryMediaIds($cat))
            ->unique()
            ->filter()
            ->values()
            ->all();

        $mediaMap = $this->buildMediaMap($allMediaIds);

        return $paginator->through(
            fn (Category $cat) => $this->buildCategoryDto($cat, $mediaMap)
        );
    }

    public function createCategory(array $data): CategoryDTO
    {
        $category = Category::query()->create($data);

        return CategoryDTO::fromModel($category, $this->resolveUrl((int) $category->media_id));
    }

    public function updateCategory(int $id, array $data): CategoryDTO
    {
        $category = Category::query()->findOrFail($id);
        $category->update($data);
        $category->refresh();

        return CategoryDTO::fromModel($category, $this->resolveUrl((int) $category->media_id));
    }

    public function deleteCategory(int $id): void
    {
        Category::query()->findOrFail($id)->delete();
    }

    // ── Brands ────────────────────────────────────────────────────────────────

    public function findBrand(int $id): ?BrandDTO
    {
        $brand = Brand::query()->find($id);

        return $brand ? BrandDTO::fromModel($brand, $this->resolveUrl((int) $brand->media_id)) : null;
    }

    public function getBrands(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Brand::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where('name', 'like', $term);
        }

        $paginator = $query->latest('id')->paginate($perPage);

        $mediaMap = $this->buildMediaMap(
            $paginator->getCollection()->pluck('media_id')->filter()->unique()->values()->all()
        );

        return $paginator->through(
            fn (Brand $brand) => BrandDTO::fromModel($brand, $mediaMap->get($brand->media_id)?->url)
        );
    }

    public function createBrand(array $data): BrandDTO
    {
        $brand = Brand::query()->create($data);

        return BrandDTO::fromModel($brand, $this->resolveUrl((int) $brand->media_id));
    }

    public function updateBrand(int $id, array $data): BrandDTO
    {
        $brand = Brand::query()->findOrFail($id);
        $brand->update($data);
        $brand->refresh();

        return BrandDTO::fromModel($brand, $this->resolveUrl((int) $brand->media_id));
    }

    public function deleteBrand(int $id): void
    {
        Brand::query()->findOrFail($id)->delete();
    }

    // ── Products ──────────────────────────────────────────────────────────────

    public function findProduct(string $uuid): ?ProductDTO
    {
        $product = Product::query()
            ->where('status', 'published')
            ->where('uuid', $uuid)
            ->with(['images', 'variants'])
            ->first();

        return $product ? $this->hydrateProduct($product) : null;
    }

    public function findProductBySlug(string $slug): ?ProductDTO
    {
        $product = Product::query()
            ->where('status', 'published')
            ->where('slug', $slug)
            ->with(['images', 'variants'])
            ->first();

        return $product ? $this->hydrateProduct($product) : null;
    }

    public function findProductAdmin(string $uuid): ?ProductDTO
    {
        $product = Product::query()
            ->where('uuid', $uuid)
            ->with(['images', 'variants'])
            ->first();

        return $product ? $this->hydrateProduct($product) : null;
    }

    public function getProductsByCategory(int $categoryId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->getProducts(array_merge($filters, ['category_id' => $categoryId]), $perPage);
    }

    public function getProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('status', 'published')
            ->with(['images', 'variants']);

        $this->applyProductFilters($query, $filters);
        $stockMap = array_key_exists('available', $filters)
            ? $this->applyProductAvailabilityFilter($query, (bool) $filters['available'])
            : null;
        $this->applyProductSort($query, $filters['sort'] ?? null);

        return $this->paginateProducts($query, $perPage, $stockMap);
    }

    public function getProductsAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['images', 'variants']);

        if (isset($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $this->applyProductFilters($query, $filters, admin: true);
        $this->applyProductSort($query, $filters['sort'] ?? null);

        return $this->paginateProducts($query, $perPage);
    }

    public function createProduct(array $data): ProductDTO
    {
        $product = Product::query()->create($data);
        $primaryImageUrl = $product->primary_media_id
            ? $this->resolveUrl((int) $product->primary_media_id)
            : null;

        return ProductDTO::fromModel($product, $primaryImageUrl, [], []);
    }

    public function addProductImage(int $productId, int $mediaId, int $sortOrder = 0): ProductImageDTO
    {
        $image = ProductImage::query()->create([
            'product_id' => $productId,
            'media_id' => $mediaId,
            'sort_order' => $sortOrder,
        ]);

        return ProductImageDTO::fromModel($image, $this->resolveUrl((int) $mediaId) ?? '');
    }

    public function removeProductImage(int $imageId): void
    {
        ProductImage::query()->findOrFail($imageId)->delete();
    }

    public function updateProduct(string $uuid, array $data): ProductDTO
    {
        $product = Product::query()->with(['images', 'variants'])->where('uuid', $uuid)->firstOrFail();
        $product->update($data);

        return $this->hydrateProduct($product->fresh(['images', 'variants']));
    }

    public function deleteProduct(string $uuid): void
    {
        Product::query()->where('uuid', $uuid)->firstOrFail()->delete();
    }

    public function syncSalesCounts(array $skuTotals): void
    {
        // Resolve the Order module's sku => total tally into product_id => summed
        // total, entirely within Catalog's own tables (SKUs map to variants,
        // multiple variants of one product accumulate). Unknown SKUs are dropped.
        $productTotals = [];

        if (! empty($skuTotals)) {
            $variantMap = ProductVariant::query()
                ->whereIn('sku', array_keys($skuTotals))
                ->pluck('product_id', 'sku');

            foreach ($skuTotals as $sku => $total) {
                $productId = $variantMap[$sku] ?? null;

                if ($productId === null) {
                    continue;
                }

                $productTotals[$productId] = ($productTotals[$productId] ?? 0) + (int) $total;
            }
        }

        DB::transaction(function () use ($productTotals) {
            // Reset first so products whose sales dropped to zero are corrected,
            // then stamp the fresh absolute totals.
            Product::query()->where('sales_count', '!=', 0)->update(['sales_count' => 0]);

            foreach ($productTotals as $productId => $total) {
                Product::query()->whereKey($productId)->update(['sales_count' => $total]);
            }
        });
    }

    // ── Product Variants ──────────────────────────────────────────────────────

    public function findVariant(int $variantId): ?ProductVariantDTO
    {
        $variant = ProductVariant::with('product')->find($variantId);

        return $variant
            ? ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title, $this->availableStockFor($variant->sku))
            : null;
    }

    public function findVariantBySku(string $sku): ?ProductVariantDTO
    {
        $variant = ProductVariant::with('product')->where('sku', $sku)->first();

        return $variant
            ? ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title, $this->availableStockFor($variant->sku))
            : null;
    }

    public function createProductVariant(int $productId, array $data): ProductVariantDTO
    {
        $variant = ProductVariant::query()->create(
            array_merge($data, ['product_id' => $productId])
        );

        $variant->load('product');

        return ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title, $this->availableStockFor($variant->sku));
    }

    public function updateProductVariant(int $variantId, array $data): ProductVariantDTO
    {
        return DB::transaction(function () use ($variantId, $data) {
            $variant = ProductVariant::query()->findOrFail($variantId);

            if (! empty($data['is_default'])) {
                ProductVariant::query()
                    ->where('product_id', $variant->product_id)
                    ->where('id', '!=', $variantId)
                    ->update(['is_default' => false]);
            }

            $variant->update($data);
            $variant->refresh();
            $variant->load('product');

            return ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title, $this->availableStockFor($variant->sku));
        });
    }

    public function deleteProductVariant(int $variantId): void
    {
        ProductVariant::query()->findOrFail($variantId)->delete();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Apply the shared category/brand/price/search filters to a product query.
     *
     * When $admin is true the free-text search also matches slug and variant SKU,
     * which storefront buyers never search on but catalog managers rely upon.
     */
    private function applyProductFilters($query, array $filters, bool $admin = false): void
    {
        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }

        if (isset($filters['min_price'])) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_default', true)
                ->where('base_price', '>=', (int) $filters['min_price'])
            );
        }

        if (array_key_exists('has_discount', $filters)) {
            if ($filters['has_discount']) {
                $query->whereHas('variants', function ($variantQuery) {
                    $variantQuery
                        ->whereNotNull('compare_at_price')
                        ->whereColumn('compare_at_price', '>', 'base_price');
                });
            } else {
                $query->whereDoesntHave('variants', function ($variantQuery) {
                    $variantQuery
                        ->whereNotNull('compare_at_price')
                        ->whereColumn('compare_at_price', '>', 'base_price');
                });
            }
        }

        if (isset($filters['max_price'])) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_default', true)
                ->where('base_price', '<=', (int) $filters['max_price'])
            );
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term, $admin) {
                $q->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('brand', fn ($bq) => $bq->where('name', 'like', $term));

                if ($admin) {
                    $q->orWhere('slug', 'like', $term)
                        ->orWhereHas('variants', fn ($vq) => $vq->where('sku', 'like', $term));
                }
            });
        }
    }

    /**
     * Apply the requested storefront ordering.
     *
     * Price sorts order by the *default* variant's base_price via a correlated
     * subquery (prices live on ProductVariant). `most_sold` uses the denormalized
     * products.sales_count. Absent/unknown sort falls back to newest-first so
     * pagination stays deterministic. All modes carry an id tiebreak.
     */
    private function applyProductSort($query, ?string $sort): void
    {
        switch ($sort) {
            case 'most_sold':
                $query->orderByDesc('sales_count')->orderByDesc('id');
                break;

            case 'cheapest':
            case 'most_expensive':
                $defaultPrice = ProductVariant::query()
                    ->select('base_price')
                    ->whereColumn('product_id', 'products.id')
                    ->where('is_default', true)
                    ->limit(1);

                $query->orderBy($defaultPrice, $sort === 'cheapest' ? 'asc' : 'desc')
                    ->orderByDesc('id');
                break;

            default:
                $query->latest('id');
        }
    }

    /**
     * Constrain the product query before pagination using one Inventory batch lookup.
     *
     * @return array<string, int> Available quantity keyed by SKU.
     */
    private function applyProductAvailabilityFilter($query, bool $available): array
    {
        $candidateProductIds = (clone $query)
            ->reorder()
            ->select('products.id');

        $skus = ProductVariant::query()
            ->whereIn('product_id', $candidateProductIds)
            ->pluck('sku')
            ->all();

        $stockMap = $this->availableStockMap($skus);
        $availableSkus = array_keys(array_filter(
            $stockMap,
            static fn (int $quantity): bool => $quantity > 0,
        ));

        if ($availableSkus === []) {
            if ($available) {
                $query->whereRaw('1 = 0');
            }

            return $stockMap;
        }

        $relation = $available ? 'whereHas' : 'whereDoesntHave';
        $query->{$relation}(
            'variants',
            fn ($variantQuery) => $variantQuery->whereIn('sku', $availableSkus),
        );

        return $stockMap;
    }

    private function paginateProducts($query, int $perPage, ?array $stockMap = null): LengthAwarePaginator
    {
        $paginator = $query->paginate($perPage);

        $mediaIds = $paginator->getCollection()
            ->flatMap(fn (Product $p) => $this->productMediaIds($p))
            ->unique()
            ->values()
            ->all();

        $mediaMap = $this->buildMediaMap($mediaIds);

        // Page-wide available-stock lookup in a single Inventory batch call, so a list
        // of products never fans out into one stock query per product.
        $stockMap ??= $this->availableStockMap(
            $paginator->getCollection()
                ->flatMap(fn (Product $p) => $p->variants->pluck('sku'))
                ->all()
        );

        return $paginator->through(fn (Product $p) => $this->hydrateProduct($p, $mediaMap, $stockMap));
    }

    private function hydrateProduct(Product $product, ?Collection $mediaMap = null, ?array $stockMap = null): ProductDTO
    {
        // Single-item callers omit the maps and get them built for this product;
        // list callers pass shared, page-wide maps each built in a single fetch.
        $mediaMap ??= $this->buildMediaMap($this->productMediaIds($product));
        $stockMap ??= $this->availableStockMap($product->variants->pluck('sku')->all());

        $primaryImageUrl = $product->primary_media_id
            ? $mediaMap->get($product->primary_media_id)?->url
            : null;

        $images = $product->images
            ->map(fn ($img) => ProductImageDTO::fromModel(
                $img,
                $mediaMap->get($img->media_id)?->url ?? ''
            ))
            ->all();

        $variants = $product->variants
            ->map(fn ($v) => ProductVariantDTO::fromModel(
                $v,
                $mediaMap->get($v->media_id)?->url,
                $product->title,
                $stockMap[$v->sku] ?? 0,
            ))
            ->all();

        return ProductDTO::fromModel($product, $primaryImageUrl, $images, $variants);
    }

    /**
     * Available units keyed by SKU, resolved from the Inventory module via its
     * contract (no cross-module table access). SKUs with no stock record are
     * simply absent from the map — callers treat that as 0.
     *
     * @param  array<int, string>  $skus
     * @return array<string, int>
     */
    private function availableStockMap(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus)));

        if ($skus === []) {
            return [];
        }

        $map = [];
        foreach ($this->inventory->getBatchStockBySkus($skus) as $sku => $stock) {
            $map[$sku] = $stock->availableQuantity;
        }

        return $map;
    }

    private function availableStockFor(string $sku): int
    {
        return $this->availableStockMap([$sku])[$sku] ?? 0;
    }

    /**
     * @return array<int, int>
     */
    private function productMediaIds(Product $product): array
    {
        return collect([$product->primary_media_id])
            ->merge($product->images->pluck('media_id'))
            ->merge($product->variants->pluck('media_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buildMediaMap(array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return $this->media->getMediaCollection($ids)->keyBy(fn (MediaDTO $dto) => $dto->id);
    }

    private function buildCategoryDto(Category $category, Collection $mediaMap, bool $withChildren = true): CategoryDTO
    {
        $parentDto = $category->parent
            ? $this->buildCategoryDto($category->parent, $mediaMap, false)
            : null;

        $children = $withChildren
            ? $category->children
                ->map(fn (Category $child) => $this->buildCategoryDto($child, $mediaMap, true))
                ->all()
            : [];

        return CategoryDTO::fromModel(
            $category,
            $mediaMap->get($category->media_id)?->url,
            $parentDto,
            $children,
        );
    }

    private function collectCategoryMediaIds(Category $category): array
    {
        $ids = $category->media_id ? [(int) $category->media_id] : [];

        if ($category->relationLoaded('parent') && $category->parent) {
            $ids = array_merge($ids, $this->collectCategoryMediaIds($category->parent));
        }

        if ($category->relationLoaded('children')) {
            foreach ($category->children as $child) {
                $ids = array_merge($ids, $this->collectCategoryMediaIds($child));
            }
        }

        return $ids;
    }

    private function resolveUrl(?int $mediaId): ?string
    {
        return $mediaId ? $this->media->getMedia($mediaId)?->url : null;
    }
}
