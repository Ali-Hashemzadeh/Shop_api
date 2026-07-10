<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Tests\TestCase;

class ProductSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function catalog(): CatalogManagerInterface
    {
        return app(CatalogManagerInterface::class);
    }

    /**
     * Create a published product with a single default variant at the given price.
     */
    private function makeProduct(string $title, string $sku, int $basePrice): Product
    {
        $product = Product::create([
            'title' => $title,
            'slug' => $sku,
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'base_price' => $basePrice,
            'is_default' => true,
            'attributes' => [],
        ]);

        return $product;
    }

    // ── Price sorts ──────────────────────────────────────────────────────────

    /** @test */
    public function it_sorts_products_cheapest_first(): void
    {
        $cheap = $this->makeProduct('Cheap', 'CHEAP', 100);
        $mid = $this->makeProduct('Mid', 'MID', 500);
        $exp = $this->makeProduct('Expensive', 'EXP', 900);

        $ids = $this->getJson('/api/v1/catalog/products?sort=cheapest')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$cheap->uuid, $mid->uuid, $exp->uuid], $ids);
    }

    /** @test */
    public function it_sorts_products_most_expensive_first(): void
    {
        $cheap = $this->makeProduct('Cheap', 'CHEAP', 100);
        $mid = $this->makeProduct('Mid', 'MID', 500);
        $exp = $this->makeProduct('Expensive', 'EXP', 900);

        $ids = $this->getJson('/api/v1/catalog/products?sort=most_expensive')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$exp->uuid, $mid->uuid, $cheap->uuid], $ids);
    }

    // ── Best-seller sort ─────────────────────────────────────────────────────

    /** @test */
    public function it_sorts_products_most_sold_first(): void
    {
        $low = $this->makeProduct('Low seller', 'LOW', 100);
        $high = $this->makeProduct('High seller', 'HIGH', 100);
        $none = $this->makeProduct('No sales', 'NONE', 100);

        $this->catalog()->syncSalesCounts(['LOW' => 5, 'HIGH' => 50, 'NONE' => 0]);

        $ids = $this->getJson('/api/v1/catalog/products?sort=most_sold')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$high->uuid, $low->uuid, $none->uuid], $ids);
    }

    /** @test */
    public function it_exposes_sales_count_on_the_product_resource(): void
    {
        $this->makeProduct('Sold', 'SOLD', 100);
        $this->catalog()->syncSalesCounts(['SOLD' => 7]);

        $this->getJson('/api/v1/catalog/products?sort=most_sold')
            ->assertOk()
            ->assertJsonPath('data.0.sales_count', 7);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    /** @test */
    public function it_rejects_an_invalid_sort_value(): void
    {
        $this->getJson('/api/v1/catalog/products?sort=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    /** @test */
    public function it_returns_products_with_no_sort_given(): void
    {
        $this->makeProduct('Alpha', 'ALPHA', 100);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Admin index sort ─────────────────────────────────────────────────────

    /** @test */
    public function admin_index_supports_the_most_sold_sort(): void
    {
        $this->actingAsAdmin();

        $low = $this->makeProduct('Low', 'A-LOW', 100);
        $high = $this->makeProduct('High', 'A-HIGH', 100);
        $this->catalog()->syncSalesCounts(['A-LOW' => 1, 'A-HIGH' => 9]);

        $ids = $this->getJson('/api/v1/catalog/products/admin?sort=most_sold')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame($high->uuid, $ids[0]);
        $this->assertSame($low->uuid, $ids[1]);
    }

    // ── syncSalesCounts semantics ────────────────────────────────────────────

    /** @test */
    public function sync_sums_multiple_variants_into_the_owning_product(): void
    {
        $product = Product::create(['title' => 'Multi', 'slug' => 'multi', 'status' => 'published']);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'M-1', 'type' => 'color', 'base_price' => 100, 'is_default' => true, 'attributes' => []]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'M-2', 'type' => 'color', 'base_price' => 100, 'is_default' => false, 'attributes' => []]);

        $this->catalog()->syncSalesCounts(['M-1' => 3, 'M-2' => 4]);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'sales_count' => 7]);
    }

    /** @test */
    public function sync_ignores_unknown_skus(): void
    {
        $product = $this->makeProduct('Known', 'KNOWN', 100);

        $this->catalog()->syncSalesCounts(['KNOWN' => 2, 'GHOST-SKU' => 99]);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'sales_count' => 2]);
    }

    /** @test */
    public function sync_resets_products_that_no_longer_have_sales(): void
    {
        $product = $this->makeProduct('Faded', 'FADED', 100);

        $this->catalog()->syncSalesCounts(['FADED' => 10]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sales_count' => 10]);

        // A later recompute where this SKU has no realized sales must zero it out.
        $this->catalog()->syncSalesCounts([]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sales_count' => 0]);
    }
}
