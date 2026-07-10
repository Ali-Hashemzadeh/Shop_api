<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Tests\TestCase;

class SalesCountSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function makeProduct(string $sku, int $basePrice = 100): Product
    {
        $product = Product::create([
            'title' => "Product {$sku}",
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

    private function makeOrderWithItem(string $status, string $sku, int $qty): void
    {
        $order = Order::create([
            'user_id' => 1,
            'status' => $status,
            'total_amount' => $qty * 100,
            'shipping_address' => ['address' => 'x'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku' => $sku,
            'product_title' => 'snapshot',
            'quantity' => $qty,
            'price_per_unit' => 100,
            'line_total' => $qty * 100,
        ]);
    }

    /** @test */
    public function it_syncs_catalog_sales_counts_from_realized_orders_only(): void
    {
        $p1 = $this->makeProduct('S-1');
        $p2 = $this->makeProduct('S-2');

        // Realized sales for S-1: paid (3) + shipped (2) = 5.
        $this->makeOrderWithItem('paid', 'S-1', 3);
        $this->makeOrderWithItem('shipped', 'S-1', 2);
        // Not-yet-realized / non-sales must be excluded.
        $this->makeOrderWithItem('pending', 'S-1', 100);
        $this->makeOrderWithItem('cancelled', 'S-1', 100);
        $this->makeOrderWithItem('failed', 'S-1', 100);

        // Realized sales for S-2: processing (4).
        $this->makeOrderWithItem('processing', 'S-2', 4);

        $this->artisan('orders:sync-sales-counts')->assertExitCode(0);

        $this->assertDatabaseHas('products', ['id' => $p1->id, 'sales_count' => 5]);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'sales_count' => 4]);
    }

    /** @test */
    public function it_zeroes_products_with_no_realized_sales(): void
    {
        $product = $this->makeProduct('S-3');
        $this->makeOrderWithItem('cancelled', 'S-3', 9);

        $this->artisan('orders:sync-sales-counts')->assertExitCode(0);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'sales_count' => 0]);
    }
}
