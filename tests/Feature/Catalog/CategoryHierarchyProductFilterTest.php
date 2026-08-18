<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Brand;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\DiscountType;
use Modules\Promotion\Domain\Models\Campaign;
use Modules\Promotion\Domain\Models\Discount;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryHierarchyProductFilterTest extends TestCase
{
    use RefreshDatabase;

    private Category $electronics;

    private Category $phones;

    private Category $android;

    private Category $pixel;

    private Category $galaxy;

    private Category $iphone;

    private Category $laptops;

    private Category $gamingLaptops;

    private Category $emptyLeaf;

    private Product $electronicsProd;

    private Product $phonesProd;

    private Product $androidProd;

    private Product $pixelProd;

    private Product $galaxyProd;

    private Product $iphoneProd;

    private Product $laptopsProd;

    private Product $gamingLaptopsProd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();

        // 4-level category hierarchy:
        // Electronics (root)
        // ├── Phones (level 1)
        // │   ├── Android (level 2)
        // │   │   ├── Pixel (level 3, leaf)
        // │   │   └── Galaxy (level 3, leaf)
        // │   └── iPhone (level 2, leaf)
        // ├── Laptops (level 1)
        // │   └── Gaming Laptops (level 2, leaf)
        // └── EmptyLeaf (leaf with no products)
        $this->electronics = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $this->phones = Category::create(['name' => 'Phones', 'slug' => 'phones', 'parent_id' => $this->electronics->id, 'is_active' => true]);
        $this->android = Category::create(['name' => 'Android', 'slug' => 'android', 'parent_id' => $this->phones->id, 'is_active' => true]);
        $this->pixel = Category::create(['name' => 'Pixel', 'slug' => 'pixel', 'parent_id' => $this->android->id, 'is_active' => true]);
        $this->galaxy = Category::create(['name' => 'Galaxy', 'slug' => 'galaxy', 'parent_id' => $this->android->id, 'is_active' => true]);
        $this->iphone = Category::create(['name' => 'iPhone', 'slug' => 'iphone', 'parent_id' => $this->phones->id, 'is_active' => true]);
        $this->laptops = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'parent_id' => $this->electronics->id, 'is_active' => true]);
        $this->gamingLaptops = Category::create(['name' => 'Gaming Laptops', 'slug' => 'gaming-laptops', 'parent_id' => $this->laptops->id, 'is_active' => true]);
        $this->emptyLeaf = Category::create(['name' => 'Empty Leaf', 'slug' => 'empty-leaf', 'parent_id' => $this->electronics->id, 'is_active' => true]);

        // Products at each level
        $this->electronicsProd = $this->createProductWithVariant('General Electronics', 'gen-elec', $this->electronics->id, 100_000);
        $this->phonesProd = $this->createProductWithVariant('Feature Phone', 'feature-phone', $this->phones->id, 200_000);
        $this->androidProd = $this->createProductWithVariant('Generic Android', 'gen-android', $this->android->id, 300_000);
        $this->pixelProd = $this->createProductWithVariant('Google Pixel 9', 'pixel-9', $this->pixel->id, 400_000);
        $this->galaxyProd = $this->createProductWithVariant('Samsung Galaxy S24', 'galaxy-s24', $this->galaxy->id, 500_000);
        $this->iphoneProd = $this->createProductWithVariant('Apple iPhone 16', 'iphone-16', $this->iphone->id, 600_000);
        $this->laptopsProd = $this->createProductWithVariant('Office Laptop', 'office-laptop', $this->laptops->id, 700_000);
        $this->gamingLaptopsProd = $this->createProductWithVariant('Alienware Gaming Laptop', 'alienware-gaming', $this->gamingLaptops->id, 800_000);
    }

    private function createProductWithVariant(
        string $title,
        string $slug,
        int $categoryId,
        int $basePrice,
        string $status = 'published',
        ?int $brandId = null,
        int $stock = 10,
        int $salesCount = 0,
    ): Product {
        $product = Product::create([
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'sales_count' => $salesCount,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => strtoupper($slug).'-01',
            'type' => 'color',
            'is_default' => true,
            'base_price' => $basePrice,
            'attributes' => ['color' => 'Black'],
        ]);

        if ($stock > 0) {
            InventoryStock::create([
                'sku' => $variant->sku,
                'quantity' => $stock,
                'reserved_quantity' => 0,
            ]);
        }

        return $product;
    }

    // ── Public Products Endpoint (/api/v1/catalog/products?category_id=...) ───

    #[Test]
    public function filtering_by_root_category_returns_all_descendants_across_the_entire_subtree(): void
    {
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->electronics->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(8, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([
            $this->electronicsProd->uuid,
            $this->phonesProd->uuid,
            $this->androidProd->uuid,
            $this->pixelProd->uuid,
            $this->galaxyProd->uuid,
            $this->iphoneProd->uuid,
            $this->laptopsProd->uuid,
            $this->gamingLaptopsProd->uuid,
        ], $returnedUuids);
    }

    #[Test]
    public function filtering_by_intermediate_category_returns_selected_plus_direct_children_and_deeper_descendants(): void
    {
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(5, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([
            $this->phonesProd->uuid,
            $this->androidProd->uuid,
            $this->pixelProd->uuid,
            $this->galaxyProd->uuid,
            $this->iphoneProd->uuid,
        ], $returnedUuids);
    }

    #[Test]
    public function filtering_by_deep_intermediate_category_returns_only_its_subtree(): void
    {
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->android->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([
            $this->androidProd->uuid,
            $this->pixelProd->uuid,
            $this->galaxyProd->uuid,
        ], $returnedUuids);
    }

    #[Test]
    public function filtering_by_leaf_category_with_no_descendants_returns_only_directly_assigned_products(): void
    {
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->pixel->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([$this->pixelProd->uuid], $returnedUuids);
    }

    #[Test]
    public function filtering_by_empty_leaf_category_returns_zero_products(): void
    {
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->emptyLeaf->id}")
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
        $this->assertCount(0, $response->json('data'));
    }

    #[Test]
    public function filtering_by_child_strictly_excludes_ancestor_products(): void
    {
        // Filtering by Android (child of Phones, grandchild of Electronics)
        // must NOT include products assigned directly to Phones or Electronics.
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->android->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($this->electronicsProd->uuid, $returnedUuids);
        $this->assertNotContains($this->phonesProd->uuid, $returnedUuids);
    }

    #[Test]
    public function filtering_by_category_strictly_excludes_sibling_subtrees(): void
    {
        // Phones subtree must NOT contain Laptops or Gaming Laptops
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($this->laptopsProd->uuid, $returnedUuids);
        $this->assertNotContains($this->gamingLaptopsProd->uuid, $returnedUuids);

        // Android subtree must NOT contain iPhone
        $androidResponse = $this->getJson("/api/v1/catalog/products?category_id={$this->android->id}")
            ->assertOk();

        $androidUuids = collect($androidResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->iphoneProd->uuid, $androidUuids);
    }

    // ── Dedicated Category Route (/api/v1/catalog/categories/{id}/products) ───

    #[Test]
    public function category_route_uses_the_same_descendant_subtree_expansion(): void
    {
        $response = $this->getJson("/api/v1/catalog/categories/{$this->phones->id}/products")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(5, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([
            $this->phonesProd->uuid,
            $this->androidProd->uuid,
            $this->pixelProd->uuid,
            $this->galaxyProd->uuid,
            $this->iphoneProd->uuid,
        ], $returnedUuids);
    }

    #[Test]
    public function category_route_for_leaf_category_returns_only_leaf_products(): void
    {
        $response = $this->getJson("/api/v1/catalog/categories/{$this->gamingLaptops->id}/products")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([$this->gamingLaptopsProd->uuid], $returnedUuids);
    }

    // ── Validation Prior to Descendant Expansion ──────────────────────────────

    #[Test]
    public function invalid_category_id_returns_422_before_descendant_expansion_on_public_list(): void
    {
        $this->getJson('/api/v1/catalog/products?category_id=99999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    #[Test]
    public function invalid_category_id_returns_422_before_descendant_expansion_on_admin_list(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/catalog/products/admin?category_id=99999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    // ── Admin Product Index (/api/v1/catalog/products/admin?category_id=...) ──

    #[Test]
    public function admin_product_index_filters_by_category_subtree_including_drafts(): void
    {
        $this->actingAsAdmin();

        $draftPixel = $this->createProductWithVariant('Draft Pixel Prototype', 'draft-pixel', $this->pixel->id, 450_000, status: 'draft');
        $draftLaptop = $this->createProductWithVariant('Draft Laptop', 'draft-laptop', $this->laptops->id, 750_000, status: 'draft');

        $response = $this->getJson("/api/v1/catalog/products/admin?category_id={$this->phones->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        // Should include published phone products + draft pixel, but NOT draft laptop
        $this->assertSame(6, $response->json('meta.total'));
        $this->assertContains($draftPixel->uuid, $returnedUuids);
        $this->assertNotContains($draftLaptop->uuid, $returnedUuids);
    }

    #[Test]
    public function admin_product_index_composes_status_filter_with_category_subtree(): void
    {
        $this->actingAsAdmin();

        $draftPixel = $this->createProductWithVariant('Draft Pixel Prototype', 'draft-pixel', $this->pixel->id, 450_000, status: 'draft');

        $response = $this->getJson("/api/v1/catalog/products/admin?category_id={$this->phones->id}&status=draft")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([$draftPixel->uuid], $returnedUuids);
    }

    // ── Filter & Sort Composition ─────────────────────────────────────────────

    #[Test]
    public function price_filters_compose_correctly_with_category_subtree_filtering(): void
    {
        // In Phones subtree:
        // Phones: 200,000
        // Android: 300,000
        // Pixel: 400,000
        // Galaxy: 500,000
        // iPhone: 600,000
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}&min_price=300000&max_price=500000")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing([
            $this->androidProd->uuid,
            $this->pixelProd->uuid,
            $this->galaxyProd->uuid,
        ], $returnedUuids);
    }

    #[Test]
    public function search_filter_composes_correctly_with_category_subtree_filtering(): void
    {
        // Searching "Pixel" in Electronics subtree matches Pixel 9
        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->electronics->id}&search=Pixel")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($this->pixelProd->uuid, $returnedUuids[0]);

        // Searching "Alienware" in Phones subtree matches nothing
        $missResponse = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}&search=Alienware")
            ->assertOk();

        $this->assertSame(0, $missResponse->json('meta.total'));
    }

    #[Test]
    public function brand_filter_composes_correctly_with_category_subtree_filtering(): void
    {
        $google = Brand::create(['name' => 'Google', 'slug' => 'google', 'is_active' => true]);
        $samsung = Brand::create(['name' => 'Samsung', 'slug' => 'samsung', 'is_active' => true]);

        $this->pixelProd->update(['brand_id' => $google->id]);
        $this->galaxyProd->update(['brand_id' => $samsung->id]);

        $response = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}&brand_id={$google->id}")
            ->assertOk();

        $returnedUuids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($this->pixelProd->uuid, $returnedUuids[0]);
    }

    #[Test]
    public function sort_orders_compose_correctly_with_category_subtree_filtering(): void
    {
        // Cheapest sort in Phones subtree: 200k, 300k, 400k, 500k, 600k
        $cheapestResponse = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}&sort=cheapest")
            ->assertOk();

        $cheapestUuids = collect($cheapestResponse->json('data'))->pluck('id')->all();
        $this->assertSame($this->phonesProd->uuid, $cheapestUuids[0]);
        $this->assertSame($this->iphoneProd->uuid, $cheapestUuids[4]);

        // Most expensive sort: 600k, 500k, 400k, 300k, 200k
        $expensiveResponse = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}&sort=most_expensive")
            ->assertOk();

        $expensiveUuids = collect($expensiveResponse->json('data'))->pluck('id')->all();
        $this->assertSame($this->iphoneProd->uuid, $expensiveUuids[0]);
        $this->assertSame($this->phonesProd->uuid, $expensiveUuids[4]);
    }

    #[Test]
    public function pagination_totals_and_page_counts_correctly_reflect_descendant_products(): void
    {
        // 5 products in Phones subtree, per_page=2 -> 3 pages
        $page1 = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}&per_page=2&page=1")
            ->assertOk();

        $this->assertSame(5, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.per_page'));
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertCount(2, $page1->json('data'));

        $page3 = $this->getJson("/api/v1/catalog/products?category_id={$this->phones->id}&per_page=2&page=3")
            ->assertOk();

        $this->assertSame(5, $page3->json('meta.total'));
        $this->assertCount(1, $page3->json('data'));
    }

    // ── Campaign Merchandising Products (/api/v1/catalog/campaigns/{slug}/products?category_id=...)

    #[Test]
    public function campaign_product_filtering_narrows_campaign_products_by_category_subtree(): void
    {
        $this->seedPromotionPermissions();

        // Create a summer sale campaign targeting Electronics category (which reaches all 8 products)
        $discount = Discount::create([
            'name' => 'Summer Electronics 10%',
            'trigger_type' => DiscountTriggerType::AUTOMATIC->value,
            'scope' => DiscountScope::TARGETED->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 1000,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        $discount->targets()->create([
            'target_type' => DiscountTargetType::CATEGORY,
            'target_id' => $this->electronics->id,
        ]);

        $campaign = Campaign::create([
            'name' => 'Summer Sale',
            'slug' => 'summer-sale',
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        $campaign->discounts()->sync([$discount->id]);

        // Campaign alone has all 8 products
        $allCampProducts = $this->getJson('/api/v1/catalog/campaigns/summer-sale/products')->assertOk();
        $this->assertSame(8, $allCampProducts->json('meta.total'));

        // Querying campaign with category_id=Phones narrows campaign products to Phones subtree (5 products)
        $filteredResponse = $this->getJson("/api/v1/catalog/campaigns/summer-sale/products?category_id={$this->phones->id}")
            ->assertOk();

        $this->assertSame(5, $filteredResponse->json('meta.total'));
        $returnedUuids = collect($filteredResponse->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([
            $this->phonesProd->uuid,
            $this->androidProd->uuid,
            $this->pixelProd->uuid,
            $this->galaxyProd->uuid,
            $this->iphoneProd->uuid,
        ], $returnedUuids);
    }
}
