<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\Province;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostTariffSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShippingParameterSeeder;

/**
 * End-to-end wiring of the dynamic Post tariff engine into checkout, plus the
 * safe-fallback behaviour that keeps a partially-configured store working.
 */
class DynamicPostShippingTest extends ShipmentTestCase
{
    private int $originProvinceId;

    private int $destinationProvinceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originProvinceId = Province::create(['name' => 'تهران'])->id;
        // A far (non-neighbour) destination — no province_neighbors row seeded for it.
        $this->destinationProvinceId = Province::create(['name' => 'فارس'])->id;

        $this->seed(ShippingParameterSeeder::class);
        $this->seed(PostTariffSeeder::class);
    }

    private function createWeightedVariant(string $sku, ?int $weightGrams, int $price = 50000, int $qty = 10): void
    {
        $product = Product::create([
            'title' => "Product {$sku}",
            'slug' => strtolower($sku),
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'base_price' => $price,
            'weight_grams' => $weightGrams,
            'is_default' => true,
            'attributes' => [],
        ]);

        InventoryStock::create(['sku' => $sku, 'quantity' => $qty, 'reserved_quantity' => 0]);
    }

    private function staticPostStandardPrice(): int
    {
        return (int) config('shipment.methods.post_standard.price');
    }

    /** @test */
    public function dynamic_post_cost_is_written_to_the_order_and_the_shipment(): void
    {
        config(['shipping.origin_province_id' => $this->originProvinceId]);

        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id, $this->destinationProvinceId, null);
        // 2000g => bracket [1000,3000) non_neighbor post_standard => base 90000.
        $this->createWeightedVariant('DYN-POST', 2000, 50000, 10);
        $this->addToCart($user->id, 'DYN-POST', 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
        ])->assertStatus(201)->json('id');

        // Merchandise (50000) + dynamic postage (90000).
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shipping_cost' => 90000,
            'total_amount' => 140000,
        ]);

        $this->markOrderPaid($orderId);

        // The dynamic figure flows all the way to the operational shipment record.
        $this->assertSame(90000, (int) Shipment::where('order_id', $orderId)->value('shipping_cost'));
    }

    /** @test */
    public function it_falls_back_to_the_static_price_when_no_origin_is_configured(): void
    {
        // Origin left unset (the production-safe default) — dynamic pricing cannot run.
        config(['shipping.origin_province_id' => null]);

        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id, $this->destinationProvinceId, null);
        $this->createWeightedVariant('FB-ORIGIN', 2000, 50000, 10);
        $this->addToCart($user->id, 'FB-ORIGIN', 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
        ])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shipping_cost' => $this->staticPostStandardPrice(),
        ]);
    }

    /** @test */
    public function it_falls_back_to_the_static_price_when_no_weight_is_captured(): void
    {
        config(['shipping.origin_province_id' => $this->originProvinceId]);

        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id, $this->destinationProvinceId, null);
        // Weightless variant — total parcel weight is 0, so dynamic pricing is skipped.
        $this->createWeightedVariant('FB-WEIGHT', null, 50000, 10);
        $this->addToCart($user->id, 'FB-WEIGHT', 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
        ])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shipping_cost' => $this->staticPostStandardPrice(),
        ]);
    }

    /** @test */
    public function pickup_keeps_its_static_price_even_with_tariffs_configured(): void
    {
        config(['shipping.origin_province_id' => $this->originProvinceId]);

        $user = $this->actingAsCustomer();
        $this->createWeightedVariant('DYN-PICKUP', 2000, 20000, 10);
        $this->addToCart($user->id, 'DYN-PICKUP', 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(201)->json('id');

        // Pickup is address-less and free by default — never touched by the Post engine.
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shipping_cost' => (int) config('shipment.methods.in_person_pickup.price'),
        ]);
    }

    /** @test */
    public function existing_postal_checkout_still_succeeds_without_any_shipping_config(): void
    {
        // Regression: with neither origin nor tariffs relevant to this parcel, a plain
        // postal checkout behaves exactly as before (static price, order created).
        config(['shipping.origin_province_id' => null]);

        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id, $this->destinationProvinceId, null);
        $this->createWeightedVariant('REG-POST', 500, 30000, 10);
        $this->addToCart($user->id, 'REG-POST', 2);

        $response = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
        ])->assertStatus(201);

        $orderId = $response->json('id');
        $expectedShipping = $this->staticPostStandardPrice();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shipping_cost' => $expectedShipping,
            'total_amount' => 60000 + $expectedShipping,
        ]);
    }
}
