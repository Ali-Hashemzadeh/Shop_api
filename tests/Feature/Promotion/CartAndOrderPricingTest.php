<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Cart\Domain\Models\Cart;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Models\Order;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use PHPUnit\Framework\Attributes\Test;

/**
 * How promotional pricing flows through the cart and freezes at checkout.
 *
 * Cart never talks to Promotion — it reads Catalog, which already applies the
 * winning discount. Cart prices are a live preview; the order is the snapshot.
 */
class CartAndOrderPricingTest extends PromotionTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->seedInventoryPermissions();
        $this->seedOrderPermissions();
        $this->seedPaymentPermissions();
        $this->seedShipmentPermissions();
        $this->seedPromotionPermissions();
    }

    private function addressId(User $user): int
    {
        return DB::table('addresses')->insertGetId([
            'user_id' => $user->id,
            'title' => 'Home',
            'province_id' => null,
            'city_id' => null,
            'postal_code' => '1234512345',
            'address' => '123 Test Street',
            'is_default_shipping' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Cart ──────────────────────────────────────────────────────────────────

    #[Test]
    public function a_cart_line_is_priced_at_the_effective_price_and_exposes_the_discount(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000, name: 'Launch Offer');

        $response = $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 3])->assertStatus(201);

        $this->assertSame(100_000_000, $response->json('items.0.base_price'));
        $this->assertSame(80_000_000, $response->json('items.0.effective_price'));
        // Quantity multiplies the EFFECTIVE price.
        $this->assertSame(240_000_000, $response->json('items.0.line_total'));
        $this->assertSame(300_000_000, $response->json('items.0.regular_line_total'));
        $this->assertSame(60_000_000, $response->json('items.0.automatic_discount_amount'));
        $this->assertSame('Launch Offer', $response->json('items.0.automatic_discount.name'));

        $this->assertSame(240_000_000, $response->json('total_price'));
        $this->assertSame(300_000_000, $response->json('regular_total_price'));
        $this->assertSame(60_000_000, $response->json('automatic_discount_total'));
    }

    #[Test]
    public function a_guest_cart_receives_the_same_promotional_price_as_an_authenticated_one(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);

        // Automatic discounts are product-context only — there are no user-specific
        // rules in v1, so identity cannot change the price.
        $guest = $this->withHeader('X-Session-Id', (string) Str::uuid())
            ->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 1])->assertStatus(201);

        $this->actingAsCustomer();
        $auth = $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 1])->assertStatus(201);

        $this->assertSame(80_000_000, $guest->json('items.0.effective_price'));
        $this->assertSame($guest->json('items.0.effective_price'), $auth->json('items.0.effective_price'));
    }

    #[Test]
    public function cart_pricing_is_live_and_follows_a_discount_being_switched_off(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);

        $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 1])
            ->assertStatus(201)
            ->assertJsonPath('items.0.effective_price', 80_000_000);

        $discount->update(['is_active' => false]);

        // Nothing promotional is stored on the cart row, so the next read is honest.
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('items.0.effective_price', 100_000_000)
            ->assertJsonPath('items.0.automatic_discount', null);
    }

    #[Test]
    public function the_cart_holds_no_coupon_state(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 1])->assertStatus(201);

        // Coupons belong to an order, never a cart.
        $this->assertArrayNotHasKey('coupon_code', $response->json());
        $this->assertArrayNotHasKey('coupon_discount_amount', $response->json());
        $this->assertFalse(Schema::hasColumn('carts', 'coupon_code'));
    }

    #[Test]
    public function a_merged_guest_cart_is_repriced_live(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        $sessionId = (string) Str::uuid();
        $this->withHeader('X-Session-Id', $sessionId)
            ->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 2])->assertStatus(201);

        // The discount starts only AFTER the guest built the cart.
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);

        $this->actingAsCustomer();
        $merged = $this->withHeader('X-Session-Id', $sessionId)
            ->postJson('/api/v1/cart/merge', ['session_id' => $sessionId])
            ->assertOk();

        $this->assertSame(80_000_000, $merged->json('items.0.effective_price'));
        $this->assertSame(160_000_000, $merged->json('total_price'));
    }

    // ── Order ─────────────────────────────────────────────────────────────────

    #[Test]
    public function checkout_snapshots_the_winning_discount_and_survives_later_changes(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000, name: 'Launch Offer');

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        $cart->items()->create(['sku' => $sku, 'quantity' => 2]);

        $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId($user),
            'shipment_method_code' => 'in_person_pickup',
        ])
            ->assertStatus(201)
            ->assertJsonPath('items.0.regular_price_per_unit', 100_000_000)
            ->assertJsonPath('items.0.automatic_discount_amount_per_unit', 20_000_000)
            ->assertJsonPath('items.0.price_per_unit', 80_000_000)
            ->assertJsonPath('items.0.line_total', 160_000_000)
            ->assertJsonPath('items.0.automatic_discount.name', 'Launch Offer')
            ->assertJsonPath('items.0.automatic_discount.percentage_bps', 2000)
            // Legacy Catalog column, never written by new orders.
            ->assertJsonPath('items.0.compare_at_price', null)
            ->assertJsonPath('total_amount', 160_000_000)
            // Coupon state starts empty and unfrozen.
            ->assertJsonPath('coupon_code', null)
            ->assertJsonPath('coupon_discount_amount', 0)
            ->assertJsonPath('coupon_snapshot', null)
            ->assertJsonPath('payment_pricing_finalized_at', null);

        // Editing the rule afterwards must not disturb the historical record.
        $discount->update(['percentage_bps' => 5000, 'name' => 'Renamed']);
        $discount->delete();

        $item = Order::where('user_id', $user->id)->latest('id')->firstOrFail()->items()->firstOrFail();
        $this->assertSame(80_000_000, $item->price_per_unit);
        $this->assertSame(100_000_000, $item->regular_price_per_unit);
        $this->assertSame('Launch Offer', $item->automatic_discount_snapshot['name']);
        $this->assertSame(2000, $item->automatic_discount_snapshot['percentage_bps']);
    }

    #[Test]
    public function checkout_uses_the_price_that_is_current_at_checkout_not_the_one_the_cart_showed(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);

        $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => 1])
            ->assertStatus(201)
            ->assertJsonPath('items.0.effective_price', 80_000_000);

        // The admin pulls the promotion while the customer is on the checkout page.
        $discount->update(['is_active' => false]);

        $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId($user),
            'shipment_method_code' => 'in_person_pickup',
        ])
            ->assertStatus(201)
            ->assertJsonPath('items.0.price_per_unit', 100_000_000)
            ->assertJsonPath('items.0.automatic_discount_amount_per_unit', 0)
            ->assertJsonPath('items.0.automatic_discount', null)
            ->assertJsonPath('total_amount', 100_000_000);
    }

    #[Test]
    public function automatic_pricing_is_not_re_evaluated_when_payment_is_initialized(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        $cart->items()->create(['sku' => $sku, 'quantity' => 1]);
        $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId($user),
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(80_000_000, $order->total_amount);

        // Deepening the discount after the order exists must not change the price.
        $discount->update(['percentage_bps' => 9000]);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
        ])->assertOk();

        $this->assertSame(80_000_000, $order->fresh()->total_amount);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 80_000_000]);
    }

    #[Test]
    public function a_coupon_stacks_on_top_of_the_automatic_price_not_the_regular_one(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 100_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        // 20% automatic → 80,000,000. Then a 10% coupon on THAT → 72,000,000.
        // Adding the two percentages first (30% of 100,000,000) would be wrong.
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);
        $this->coupon('SUMMER10', bps: 1000);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        $cart->items()->create(['sku' => $sku, 'quantity' => 1]);
        $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId($user),
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'SUMMER10'])
            ->assertOk()
            ->assertJsonPath('merchandise_subtotal', 80_000_000)
            ->assertJsonPath('discount_amount', 8_000_000)
            ->assertJsonPath('total_after_coupon', 72_000_000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'SUMMER10',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(72_000_000, $order->total_amount);
        $this->assertSame(8_000_000, $order->coupon_discount_amount);

        // The coupon is recorded at order level only — items are untouched.
        $item = $order->items()->firstOrFail();
        $this->assertSame(80_000_000, $item->price_per_unit);
        $this->assertSame(80_000_000, $item->line_total);
    }

    #[Test]
    public function a_coupon_is_never_allocated_across_order_items(): void
    {
        $user = $this->actingAsCustomer();
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        foreach (range(1, 4) as $i) {
            $product = $this->makeProduct("Item {$i}", 10_000_000);
            $sku = $this->defaultVariant($product)->sku;
            InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);
            $cart->items()->create(['sku' => $sku, 'quantity' => 1]);
        }

        $this->coupon('FLAT', fixed: 4_000_000);

        $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId($user),
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'FLAT',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(36_000_000, $order->total_amount);
        $this->assertSame(4_000_000, $order->coupon_discount_amount);

        // Every item keeps its own price — no 1,000,000 spread across four lines.
        foreach ($order->items as $item) {
            $this->assertSame(10_000_000, $item->price_per_unit);
            $this->assertSame(10_000_000, $item->line_total);
        }
    }

    #[Test]
    public function a_coupon_that_would_zero_the_order_is_rejected(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->makeProduct('Phone', 10_000_000);
        $sku = $this->defaultVariant($product)->sku;
        InventoryStock::create(['sku' => $sku, 'quantity' => 10, 'reserved_quantity' => 0]);

        // A fixed amount at or above the whole merchandise subtotal (free pickup).
        $this->coupon('FREEBIE', fixed: 10_000_000);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        $cart->items()->create(['sku' => $sku, 'quantity' => 1]);
        $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId($user),
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'FREEBIE',
        ])->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        // The rejection rolled back the reservation — the coupon was not consumed.
        $this->assertDatabaseCount('coupon_redemptions', 0);
        $order->refresh();
        $this->assertNull($order->payment_pricing_finalized_at);
        $this->assertSame(10_000_000, $order->total_amount);
    }
}
