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
        $category = Category::query()->find($id);

        return $category
            ? CategoryDTO::fromModel($category, $this->resolveUrl($category->media_id))
            : null;
    }

    public function getActiveRootCategories(int $perPage = 15): LengthAwarePaginator
    {
        $paginator = Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->paginate($perPage);

        $mediaMap = $this->buildMediaMap($paginator->pluck('media_id')->filter()->all());

        return $paginator->through(
            fn (Category $cat) => CategoryDTO::fromModel($cat, $mediaMap->get($cat->media_id)?->url)
        );
    }

    public function createCategory(array $data): CategoryDTO
    {
        $category = Category::query()->create($data);

        return CategoryDTO::fromModel($category, $this->resolveUrl($category->media_id));
    }

    public function updateCategory(int $id, array $data): CategoryDTO
    {
        $category = Category::query()->findOrFail($id);
        $category->update($data);
        $category->refresh();

        return CategoryDTO::fromModel($category, $this->resolveUrl($category->media_id));
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

    public function getProductsByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        return Product::query()
            ->where('status', 'published')
            ->where('category_id', $categoryId)
            ->with(['images', 'variants'])
            ->paginate($perPage)
            ->through(fn (Product $p) => $this->hydrateProduct($p));
    }

    public function createProduct(array $data): ProductDTO
    {
        $product         = Product::query()->create($data);
        $primaryImageUrl = $product->primary_media_id
            ? $this->resolveUrl($product->primary_media_id)
            : null;

        return ProductDTO::fromModel($product, $primaryImageUrl, [], []);
    }

    public function addProductImage(int $productId, int $mediaId, int $sortOrder = 0): void
    {
        ProductImage::query()->create([
            'product_id' => $productId,
            'media_id'   => $mediaId,
            'sort_order' => $sortOrder,
        ]);
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
        $variant = ProductVariant::query()->find($variantId);

        return $variant
            ? ProductVariantDTO::fromModel($variant, $this->resolveUrl($variant->media_id))
            : null;
    }

    public function findVariantBySku(string $sku): ?ProductVariantDTO
    {
        $variant = ProductVariant::query()->where('sku', $sku)->first();

        return $variant
            ? ProductVariantDTO::fromModel($variant, $this->resolveUrl($variant->media_id))
            : null;
    }

    public function createProductVariant(int $productId, array $data): ProductVariantDTO
    {
        $variant = ProductVariant::query()->create(
            array_merge($data, ['product_id' => $productId])
        );

        return ProductVariantDTO::fromModel($variant, $this->resolveUrl($variant->media_id));
    }

    public function updateVariantPrice(int $variantId, int $basePrice, ?int $compareAtPrice = null): ProductVariantDTO
    {
        $variant = ProductVariant::query()->findOrFail($variantId);
        $variant->update([
            'base_price'       => $basePrice,
            'compare_at_price' => $compareAtPrice,
        ]);
        $variant->refresh();

        return ProductVariantDTO::fromModel($variant, $this->resolveUrl($variant->media_id));
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

            return ProductVariantDTO::fromModel($variant, $this->resolveUrl($variant->media_id));
        });
    }

    public function deleteProductVariant(int $variantId): void
    {
        ProductVariant::query()->findOrFail($variantId)->delete();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function hydrateProduct(Product $product): ProductDTO
    {
        $mediaIds = collect([$product->primary_media_id])
            ->merge($product->images->pluck('media_id'))
            ->merge($product->variants->pluck('media_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $mediaMap = $this->buildMediaMap($mediaIds);

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
                $mediaMap->get($v->media_id)?->url
            ))
            ->all();

        return ProductDTO::fromModel($product, $primaryImageUrl, $images, $variants);
    }

    private function buildMediaMap(array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return $this->media->getMediaCollection($ids)->keyBy(fn (MediaDTO $dto) => $dto->id);
    }

    private function resolveUrl(?int $mediaId): ?string
    {
        return $mediaId ? $this->media->getMedia($mediaId)?->url : null;
    }
}
