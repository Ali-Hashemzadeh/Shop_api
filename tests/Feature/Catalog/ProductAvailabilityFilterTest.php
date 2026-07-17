<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\DTOs\InventoryStockDTO;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

class ProductAvailabilityFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function available_true_returns_only_products_with_positive_available_stock(): void
    {
        [$available] = $this->createAvailabilityFixtures();

        $this->getJson('/api/v1/catalog/products?available=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->uuid)
            ->assertJsonPath('data.0.variants.0.stock', 1)
            ->assertJsonPath('meta.total', 1);
    }

    /** @test */
    public function available_false_returns_fully_reserved_and_missing_stock_products(): void
    {
        [, $fullyReserved, $missingStock] = $this->createAvailabilityFixtures();

        $response = $this->getJson('/api/v1/catalog/products?available=false')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->assertEqualsCanonicalizing(
            [$fullyReserved->uuid, $missingStock->uuid],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    /** @test */
    public function invalid_available_values_return_the_standard_validation_error(): void
    {
        foreach (['1', '0', 'TRUE', 'maybe', ''] as $value) {
            $this->getJson('/api/v1/catalog/products?available='.urlencode($value))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['available']);
        }
    }

    /** @test */
    public function omitting_available_preserves_the_unfiltered_product_index(): void
    {
        $this->createAvailabilityFixtures();

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    /** @test */
    public function availability_filter_uses_one_inventory_batch_call_for_all_candidate_products(): void
    {
        [$available, $fullyReserved] = $this->createProductsAndVariants();

        $this->mock(InventoryManagerInterface::class, function ($mock) {
            $mock->shouldReceive('getBatchStockBySkus')
                ->once()
                ->withArgs(function (array $skus): bool {
                    sort($skus);

                    return $skus === ['AVAILABLE-1', 'RESERVED-1'];
                })
                ->andReturn([
                    'AVAILABLE-1' => new InventoryStockDTO('AVAILABLE-1', 3, 3, 0),
                    'RESERVED-1' => new InventoryStockDTO('RESERVED-1', 0, 3, 3),
                ]);
        });

        $this->getJson('/api/v1/catalog/products?available=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->uuid)
            ->assertJsonMissing(['id' => $fullyReserved->uuid]);
    }

    /**
     * @return array{Product, Product, Product}
     */
    private function createAvailabilityFixtures(): array
    {
        [$available, $fullyReserved, $missingStock] = $this->createProductsAndVariants(includeMissing: true);

        InventoryStock::create([
            'sku' => 'AVAILABLE-1',
            'quantity' => 5,
            'reserved_quantity' => 4,
        ]);
        InventoryStock::create([
            'sku' => 'RESERVED-1',
            'quantity' => 5,
            'reserved_quantity' => 5,
        ]);

        return [$available, $fullyReserved, $missingStock];
    }

    /**
     * @return array<int, Product>
     */
    private function createProductsAndVariants(bool $includeMissing = false): array
    {
        $products = [
            $this->createProductWithVariant('Available Product', 'available-product', 'AVAILABLE-1'),
            $this->createProductWithVariant('Fully Reserved Product', 'fully-reserved-product', 'RESERVED-1'),
        ];

        if ($includeMissing) {
            $products[] = $this->createProductWithVariant('Missing Stock Product', 'missing-stock-product', 'MISSING-1');
        }

        return $products;
    }

    private function createProductWithVariant(string $title, string $slug, string $sku): Product
    {
        $product = Product::create([
            'title' => $title,
            'slug' => $slug,
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'is_default' => true,
            'base_price' => 1000,
            'attributes' => [],
        ]);

        return $product;
    }
}
