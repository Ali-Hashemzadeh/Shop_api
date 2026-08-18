<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Persistence\Repositories;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
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
use Modules\Catalog\Domain\Services\CategoryHierarchy;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\DTOs\AutomaticDiscountContextDTO;
use Modules\Promotion\Domain\DTOs\AutomaticDiscountResultDTO;
use Modules\Promotion\Domain\DTOs\AutomaticTargetDefinitionsDTO;

class EloquentCatalogManager implements CatalogManagerInterface
{
    public function __construct(
        private readonly MediaManagerInterface $media,
        private readonly InventoryManagerInterface $inventory,
        // Catalog depends on Promotion, never the reverse: Promotion is handed the
        // product context it needs and answers with pricing, so it never queries
        // Catalog and no cycle forms.
        private readonly PromotionManagerInterface $promotion,
        private readonly CategoryHierarchy $categories,
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

    public function getActiveRootCategories(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Category::query()
            ->where('is_active', true)
            ->with('children.children.children.children.children');

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        if ($search === '') {
            // Unsearched listing is the storefront root menu, exactly as before.
            $query->whereNull('parent_id');
        } elseif (PublicCodeGenerator::matches($search, PublicCodeEntity::Category)) {
            // A recognizable category code is an exact indexed lookup, never a
            // LIKE scan — and it resolves at any depth, since a customer holding
            // `bdc-T4K8NP` has no idea whether that category is a root.
            $query->where('public_code', PublicCodeGenerator::normalize($search));
        } else {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%');
            });
        }

        $paginator = $query->paginate($perPage);

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
        $category = Category::createWithPublicCode($data);

        return CategoryDTO::fromModel($category, $this->resolveUrl((int) $category->media_id));
    }

    public function updateCategory(int $id, array $data): CategoryDTO
    {
        // Public codes are immutable — an update never reassigns one.
        unset($data['public_code']);

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

    public function getCampaignProducts(string $slug, array $filters = [], int $perPage = 15): ?LengthAwarePaginator
    {
        // Promotion answers only "which Catalog ids does this campaign reach?".
        // Null means the campaign is missing, inactive, or outside its window.
        $definitions = $this->promotion->getCampaignTargetDefinitions($slug);

        if ($definitions === null) {
            return null;
        }

        $query = Product::query()
            ->where('status', 'published')
            ->with(['images', 'variants']);

        if ($definitions->isEmpty()) {
            // A live campaign whose rules have all expired merchandises nothing —
            // an empty page, not a 404, because the campaign itself still exists.
            $query->whereRaw('1 = 0');
        } else {
            $this->applyTargetDefinitionsFilter($query, $definitions);
        }

        $this->applyProductFilters($query, $filters);
        $this->applyProductSort($query, $filters['sort'] ?? null);

        // A single DISTINCT-free query: the OR-group below cannot duplicate a product
        // row, because whereHas is a subquery rather than a join. Products matched by
        // several of the campaign's rules therefore appear exactly once.
        return $this->paginateProducts($query, $perPage);
    }

    /**
     * Turn Promotion's raw target ids into a Catalog-side OR constraint.
     *
     * Variant targets resolve to their owning product; category targets expand to
     * descendants. Shared by the has_discount filter and campaign product lists so
     * the two can never disagree about what a target means.
     */
    private function applyTargetDefinitionsFilter($query, AutomaticTargetDefinitionsDTO $definitions): void
    {
        $categoryIds = $this->categories->descendantsOf($definitions->categoryIds);

        $query->where(fn ($q) => $this->targetDefinitionsConstraint($q, $definitions, $categoryIds));
    }

    /**
     * The OR-group shared by the has_discount filter and campaign product lists.
     *
     * `category_id` and `brand_id` are nullable, and in SQL `NULL IN (...)` is NULL
     * rather than false — so under a NOT() the whole group would evaluate to NULL
     * and silently drop every uncategorized, brandless product from a
     * `has_discount=false` page. The explicit IS NOT NULL guards force a real
     * boolean, which is what makes the negation correct.
     *
     * @param  array<int, int>  $categoryIds  Already expanded to descendants.
     */
    private function targetDefinitionsConstraint($query, AutomaticTargetDefinitionsDTO $definitions, array $categoryIds): void
    {
        // Seed with a false literal so every branch below can be an OR.
        $query->whereRaw('1 = 0');

        if ($definitions->productIds !== []) {
            $query->orWhereIn('products.id', $definitions->productIds);
        }

        if ($definitions->brandIds !== []) {
            $query->orWhere(fn ($q) => $q
                ->whereNotNull('products.brand_id')
                ->whereIn('products.brand_id', $definitions->brandIds));
        }

        if ($categoryIds !== []) {
            $query->orWhere(fn ($q) => $q
                ->whereNotNull('products.category_id')
                ->whereIn('products.category_id', $categoryIds));
        }

        if ($definitions->variantIds !== []) {
            $query->orWhereHas('variants', fn ($vq) => $vq->whereIn('id', $definitions->variantIds));
        }
    }

    public function createProduct(array $data): ProductDTO
    {
        // createWithPublicCode: the `uuid` public code is minted by the model hook
        // and retried here if the unique index rejects a concurrent duplicate.
        $product = Product::createWithPublicCode($data);
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

        return $variant ? $this->hydrateVariant($variant) : null;
    }

    /**
     * Build one variant DTO with live pricing. The single-item counterpart to the
     * batched paths above; used by create/update/show, never inside a loop.
     */
    private function hydrateVariant(ProductVariant $variant): ProductVariantDTO
    {
        $discountMap = $this->discountMapForVariants(collect([$variant]));
        $ancestors = $this->categories->ancestorsFor([$variant->product?->category_id]);
        $categoryIds = $ancestors[$variant->product?->category_id] ?? [];

        return ProductVariantDTO::fromModel(
            $variant,
            $this->resolveUrl((int) $variant->media_id),
            $variant->product?->title,
            $this->availableStockFor($variant->sku),
            null,
            $discountMap[$variant->id] ?? null,
            (int) $variant->product_id,
            $categoryIds,
        );
    }

    public function findVariantBySku(string $sku): ?ProductVariantDTO
    {
        return $this->getVariantsBySkus([$sku])[$sku] ?? null;
    }

    public function getVariantsBySkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus)));

        if ($skus === []) {
            return [];
        }

        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('sku', $skus)
            ->get();
        $stockMap = $this->availableStockMap($variants->pluck('sku')->all());
        // One Promotion call for every SKU asked for — this is the path Cart uses
        // for the whole basket and Order uses for the whole checkout.
        $discountMap = $this->discountMapForVariants($variants);
        $ancestors = $this->categories->ancestorsFor(
            $variants->pluck('product.category_id')->filter()->unique()->all()
        );
        $mediaIds = $variants
            ->flatMap(fn (ProductVariant $variant): array => [
                $variant->media_id,
                $variant->product?->primary_media_id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $mediaMap = $this->buildMediaMap($mediaIds);

        return $variants->mapWithKeys(fn (ProductVariant $variant): array => [
            $variant->sku => ProductVariantDTO::fromModel(
                $variant,
                $mediaMap->get($variant->media_id)?->url,
                $variant->product?->title,
                $stockMap[$variant->sku] ?? 0,
                $variant->product?->primary_media_id
                    ? $mediaMap->get($variant->product->primary_media_id)?->url
                    : null,
                $discountMap[$variant->id] ?? null,
                (int) $variant->product_id,
                $ancestors[$variant->product?->category_id] ?? [],
            ),
        ])->all();
    }

    /**
     * The single place a variant SKU is minted.
     *
     * Any inbound `sku` is discarded rather than honoured: the SKU is a
     * server-owned public code, so no caller — action, controller, or another
     * module — may choose one. The model's creating hook assigns it.
     */
    public function createProductVariant(int $productId, array $data): ProductVariantDTO
    {
        unset($data['sku']);

        $variant = ProductVariant::createWithPublicCode(
            array_merge($data, ['product_id' => $productId])
        );

        $variant->load('product');

        return $this->hydrateVariant($variant);
    }

    public function updateProductVariant(int $variantId, array $data): ProductVariantDTO
    {
        // A SKU is never regenerated and never reassigned on update — Inventory,
        // Cart, order items, and reservations all key off the existing value.
        unset($data['sku']);

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

            return $this->hydrateVariant($variant);
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
            $categoryIds = $this->categories->descendantsOf([(int) $filters['category_id']]);
            $query->whereIn('category_id', $categoryIds);
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
            $this->applyHasDiscountFilter($query, (bool) $filters['has_discount']);
        }

        if (isset($filters['max_price'])) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_default', true)
                ->where('base_price', '<=', (int) $filters['max_price'])
            );
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            if (! $admin && $this->applyPublicCodeSearch($query, (string) $filters['search'])) {
                return;
            }

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
     * Storefront-only shortcut: a search term that *is* a product or variant code
     * resolves to exactly one product through its unique index.
     *
     * A customer pasting `bdp-K92XMQ` or a variant's `bdv-R7P4NZ` off an invoice
     * wants that one item, not every row whose description happens to contain the
     * string — so this is exact equality, never a LIKE scan, and never a partial
     * match. Deliberately not applied to the admin list, which keeps its existing
     * substring behaviour over slug and SKU.
     *
     * @return bool true when the term was consumed as a code
     */
    private function applyPublicCodeSearch($query, string $search): bool
    {
        if (PublicCodeGenerator::matches($search, PublicCodeEntity::Product)) {
            $query->where('uuid', PublicCodeGenerator::normalize($search));

            return true;
        }

        if (PublicCodeGenerator::matches($search, PublicCodeEntity::ProductVariant)) {
            $sku = PublicCodeGenerator::normalize($search);
            $query->whereHas('variants', fn ($vq) => $vq->where('sku', $sku));

            return true;
        }

        return false;
    }

    /**
     * Constrain a product query to products that do (or do not) currently carry an
     * applicable automatic discount.
     *
     * Expressed entirely as SQL on Catalog's own tables. Filtering a fetched page in
     * PHP instead would corrupt `total`, `last_page`, and every page after the
     * first, so the constraint has to be part of the query that paginates.
     *
     * Promotion contributes only raw target ids through its contract — there is no
     * join across the module wall.
     */
    private function applyHasDiscountFilter($query, bool $hasDiscount): void
    {
        $definitions = $this->promotion->getActiveAutomaticTargetDefinitions();

        if ($definitions->isEmpty()) {
            // Nothing is discounted, so "on sale" matches nothing and "not on sale"
            // matches everything — no constraint needed in the latter case.
            if ($hasDiscount) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        // A discount aimed at "Electronics" reaches products filed under "Android".
        // Catalog owns the hierarchy, so it expands the targeted ids downwards;
        // Promotion never walks the tree.
        $categoryIds = $this->categories->descendantsOf($definitions->categoryIds);

        $constraint = fn ($q) => $this->targetDefinitionsConstraint($q, $definitions, $categoryIds);

        $hasDiscount ? $query->where($constraint) : $query->whereNot($constraint);
    }

    /**
     * Price a whole page of products in ONE Promotion call.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, AutomaticDiscountResultDTO> keyed by variant id
     */
    private function discountMapForProducts($products): array
    {
        $contexts = [];
        // One hierarchy resolution for every category on the page, not one per product.
        $ancestors = $this->categories->ancestorsFor(
            $products->pluck('category_id')->filter()->unique()->all()
        );

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $contexts[] = new AutomaticDiscountContextDTO(
                    variantId: $variant->id,
                    productId: $product->id,
                    categoryIds: $ancestors[$product->category_id] ?? [],
                    brandId: $product->brand_id === null ? null : (int) $product->brand_id,
                    basePrice: (int) $variant->base_price,
                );
            }
        }

        return $this->promotion->evaluateAutomaticDiscounts($contexts);
    }

    /**
     * Price a set of standalone variants (each carrying its product) in one call.
     * Used by the SKU-keyed lookups that Cart and Order checkout depend on.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, AutomaticDiscountResultDTO> keyed by variant id
     */
    private function discountMapForVariants($variants): array
    {
        $withProduct = $variants->filter(fn (ProductVariant $variant): bool => $variant->product !== null);

        if ($withProduct->isEmpty()) {
            return [];
        }

        $ancestors = $this->categories->ancestorsFor(
            $withProduct->pluck('product.category_id')->filter()->unique()->all()
        );

        $contexts = $withProduct->map(fn (ProductVariant $variant): AutomaticDiscountContextDTO => new AutomaticDiscountContextDTO(
            variantId: $variant->id,
            productId: (int) $variant->product_id,
            categoryIds: $ancestors[$variant->product->category_id] ?? [],
            brandId: $variant->product->brand_id === null ? null : (int) $variant->product->brand_id,
            basePrice: (int) $variant->base_price,
        ))->values()->all();

        return $this->promotion->evaluateAutomaticDiscounts($contexts);
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

        // Same batching discipline as media and stock: the entire page is priced in
        // a single Promotion call, so a 100-product listing never becomes 100
        // discount lookups.
        $discountMap = $this->discountMapForProducts($paginator->getCollection());

        return $paginator->through(fn (Product $p) => $this->hydrateProduct($p, $mediaMap, $stockMap, $discountMap));
    }

    private function hydrateProduct(Product $product, ?Collection $mediaMap = null, ?array $stockMap = null, ?array $discountMap = null): ProductDTO
    {
        // Single-item callers omit the maps and get them built for this product;
        // list callers pass shared, page-wide maps each built in a single fetch.
        $mediaMap ??= $this->buildMediaMap($this->productMediaIds($product));
        $stockMap ??= $this->availableStockMap($product->variants->pluck('sku')->all());
        $discountMap ??= $this->discountMapForProducts(collect([$product]));

        $primaryImageUrl = $product->primary_media_id
            ? $mediaMap->get($product->primary_media_id)?->url
            : null;

        $images = $product->images
            ->map(fn ($img) => ProductImageDTO::fromModel(
                $img,
                $mediaMap->get($img->media_id)?->url ?? ''
            ))
            ->all();

        $ancestors = $this->categories->ancestorsFor([$product->category_id]);
        $categoryIds = $ancestors[$product->category_id] ?? [];

        $variants = $product->variants
            ->map(fn ($v) => ProductVariantDTO::fromModel(
                $v,
                $mediaMap->get($v->media_id)?->url,
                $product->title,
                $stockMap[$v->sku] ?? 0,
                $primaryImageUrl,
                $discountMap[$v->id] ?? null,
                (int) $product->id,
                $categoryIds,
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
