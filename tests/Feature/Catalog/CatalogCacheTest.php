<?php

namespace Tests\Feature\Catalog;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Catalog\Infrastructure\Persistence\Repositories\CachedCatalogManager;
use Modules\Catalog\Infrastructure\Persistence\Repositories\EloquentCatalogManager;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    private CachedCatalogManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->seedMediaPermissions();

        Cache::flush();

        $this->manager = new CachedCatalogManager(
            app(EloquentCatalogManager::class),
            app(CacheRepository::class),
            3600,
        );
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @test */
    public function it_caches_find_category_and_serves_second_call_without_db(): void
    {
        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $this->manager->findCategory($category->id);

        DB::enableQueryLog();
        $dto = $this->manager->findCategory($category->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'Second findCategory call must be served from cache');
        $this->assertSame('Electronics', $dto->name);
    }

    /** @test */
    public function it_invalidates_category_item_cache_on_update(): void
    {
        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $this->manager->findCategory($category->id);

        $this->manager->updateCategory($category->id, ['name' => 'Updated Electronics']);

        DB::enableQueryLog();
        $dto = $this->manager->findCategory($category->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queries, 'After updateCategory, findCategory must re-fetch from DB');
        $this->assertSame('Updated Electronics', $dto->name);
    }

    /** @test */
    public function it_bumps_category_version_on_write_so_list_cache_is_invalidated(): void
    {
        $versionBefore = (int) Cache::get('catalog.categories.version', 0);

        $this->manager->createCategory(['name' => 'New Category', 'slug' => 'new-category', 'is_active' => true]);

        $versionAfter = (int) Cache::get('catalog.categories.version', 0);
        $this->assertGreaterThan($versionBefore, $versionAfter, 'Category list version must increment on write');
    }

    /** @test */
    public function it_caches_find_product_and_serves_second_call_without_db(): void
    {
        $product = Product::create(['title' => 'Widget', 'slug' => 'widget', 'status' => 'published']);

        $this->manager->findProduct($product->uuid);

        DB::enableQueryLog();
        $dto = $this->manager->findProduct($product->uuid);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'Second findProduct call must be served from cache');
        $this->assertSame('Widget', $dto->title);
    }

    /** @test */
    public function it_invalidates_product_and_slug_caches_on_update(): void
    {
        $product = Product::create(['title' => 'Widget', 'slug' => 'widget', 'status' => 'published']);

        $this->manager->findProduct($product->uuid);
        $this->manager->findProductBySlug('widget');

        $this->manager->updateProduct($product->uuid, ['title' => 'Widget Pro']);

        DB::enableQueryLog();
        $byId = $this->manager->findProduct($product->uuid);
        $bySlug = $this->manager->findProductBySlug('widget');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queries, 'After updateProduct, both caches must be busted');
        $this->assertSame('Widget Pro', $byId->title);
        $this->assertSame('Widget Pro', $bySlug->title);
    }

    /** @test */
    public function it_caches_find_variant_by_sku_and_serves_second_call_without_db(): void
    {
        $product = Product::create(['title' => 'Phone', 'slug' => 'phone', 'status' => 'published']);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PHONE-001',
            'type' => 'color',
            'is_default' => true,
            'base_price' => 500000,
        ]);

        $this->manager->findVariantBySku('PHONE-001');

        DB::enableQueryLog();
        $dto = $this->manager->findVariantBySku('PHONE-001');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'Second findVariantBySku call must be served from cache');
        $this->assertSame(500000, $dto->basePrice);
    }

    /** @test */
    public function it_invalidates_sku_cache_after_variant_update(): void
    {
        $product = Product::create(['title' => 'Phone', 'slug' => 'phone', 'status' => 'published']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PHONE-001',
            'type' => 'color',
            'is_default' => true,
            'base_price' => 500000,
        ]);

        $this->manager->findVariantBySku('PHONE-001');

        $this->manager->updateProductVariant($variant->id, ['base_price' => 750000]);

        DB::enableQueryLog();
        $dto = $this->manager->findVariantBySku('PHONE-001');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queries, 'After updateProductVariant, SKU cache must be busted');
        $this->assertSame(750000, $dto->basePrice);
    }

    /** @test */
    public function it_never_caches_find_product_admin(): void
    {
        $product = Product::create(['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft']);

        $this->manager->findProductAdmin($product->uuid);

        DB::enableQueryLog();
        $this->manager->findProductAdmin($product->uuid);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queries, 'findProductAdmin must always hit the database — never cached');
    }
}
