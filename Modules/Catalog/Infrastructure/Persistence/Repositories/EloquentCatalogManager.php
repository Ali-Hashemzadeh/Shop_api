<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\CategoryDTO;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\DTOs\ProductImageDTO;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductImage;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;

class EloquentCatalogManager implements CatalogManagerInterface
{
    public function __construct(private readonly MediaManagerInterface $media) {}

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

    // ── Products ──────────────────────────────────────────────────────────────

    public function findProduct(int $id): ?ProductDTO
    {
        $product = Product::query()
            ->where('status', 'published')
            ->with(['images', 'variants'])
            ->find($id);

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

    public function findProductAdmin(int $id): ?ProductDTO
    {
        $product = Product::query()
            ->with(['images', 'variants'])
            ->find($id);

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

        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['min_price'])) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_default', true)
                ->where('base_price', '>=', (int) $filters['min_price'])
            );
        }

        if (isset($filters['max_price'])) {
            $query->whereHas('variants', fn ($q) => $q
                ->where('is_default', true)
                ->where('base_price', '<=', (int) $filters['max_price'])
            );
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q
                ->where('title', 'like', $term)
                ->orWhere('description', 'like', $term)
            );
        }

        $paginator = $query->paginate($perPage);

        $mediaIds = $paginator->getCollection()
            ->flatMap(fn (Product $p) => $this->productMediaIds($p))
            ->unique()
            ->values()
            ->all();

        $mediaMap = $this->buildMediaMap($mediaIds);

        return $paginator->through(fn (Product $p) => $this->hydrateProduct($p, $mediaMap));
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

    public function updateProduct(int $id, array $data): ProductDTO
    {
        $product = Product::query()->with(['images', 'variants'])->findOrFail($id);
        $product->update($data);

        return $this->hydrateProduct($product->fresh(['images', 'variants']));
    }

    public function deleteProduct(int $id): void
    {
        Product::query()->findOrFail($id)->delete();
    }

    // ── Product Variants ──────────────────────────────────────────────────────

    public function findVariant(int $variantId): ?ProductVariantDTO
    {
        $variant = ProductVariant::with('product')->find($variantId);

        return $variant
            ? ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title)
            : null;
    }

    public function findVariantBySku(string $sku): ?ProductVariantDTO
    {
        $variant = ProductVariant::with('product')->where('sku', $sku)->first();

        return $variant
            ? ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title)
            : null;
    }

    public function createProductVariant(int $productId, array $data): ProductVariantDTO
    {
        $variant = ProductVariant::query()->create(
            array_merge($data, ['product_id' => $productId])
        );

        $variant->load('product');

        return ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title);
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

            return ProductVariantDTO::fromModel($variant, $this->resolveUrl((int) $variant->media_id), $variant->product?->title);
        });
    }

    public function deleteProductVariant(int $variantId): void
    {
        ProductVariant::query()->findOrFail($variantId)->delete();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function hydrateProduct(Product $product, ?Collection $mediaMap = null): ProductDTO
    {
        // Single-item callers omit the map and get one built for this product;
        // list callers pass a shared, page-wide map built in a single fetch.
        $mediaMap ??= $this->buildMediaMap($this->productMediaIds($product));

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
            ))
            ->all();

        return ProductDTO::fromModel($product, $primaryImageUrl, $images, $variants);
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
