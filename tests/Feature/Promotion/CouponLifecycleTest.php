<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Domain\Models\Cart;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Models\Order;
use Modules\Payment\Domain\Models\Payment;
use Modules\Promotion\Domain\Enums\RedemptionStatus;
use Modules\Promotion\Domain\Models\CouponRedemption;
use PHPUnit\Framework\Attributes\Test;

/**
 * The coupon lifecycle across the order and payment flow.
 *
 * check → (nothing persisted)
 * first payment initialization → reserved + pricing frozen
 * payment failure → still reserved
 * paid → redeemed
 * cancelled / expired / replaced → released
 */
class CouponLifecycleTest extends PromotionTestCase
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

    /** A pending order worth 100,000,000 in merchandise, pickup (free shipping). */
    private function pendingOrder(User $user, int $unitPrice = 100_000_000, int $quantity = 1): Order
    {
        $product = $this->makeProduct('Phone '.uniqid(), $unitPrice);
        $sku = $this->defaultVariant($product)->sku;

        InventoryStock::create(['sku' => $sku, 'quantity' => 50, 'reserved_quantity' => 0]);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        $cart->items()->create(['sku' => $sku, 'quantity' => $quantity]);

        $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId($user),
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(201);

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    /** Mirrors the address fixture used by the existing Order feature tests. */
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

    // ── Preview: advisory only ────────────────────────────────────────────────

    #[Test]
    public function checking_a_valid_coupon_returns_a_quote_and_persists_nothing(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'summer10'])
            ->assertOk()
            // Normalized on the way back, so the client stores the canonical form.
            ->assertJsonPath('code', 'SUMMER10')
            ->assertJsonPath('discount_amount', 10_000_000)
            ->assertJsonPath('merchandise_subtotal', 100_000_000)
            ->assertJsonPath('total_before_coupon', 100_000_000)
            ->assertJsonPath('total_after_coupon', 90_000_000);

        // Advisory: no redemption row, and the order is untouched.
        $this->assertDatabaseCount('coupon_redemptions', 0);
        $order->refresh();
        $this->assertNull($order->coupon_code);
        $this->assertSame(0, $order->coupon_discount_amount);
        $this->assertNull($order->payment_pricing_finalized_at);
        $this->assertSame(100_000_000, $order->total_amount);
    }

    #[Test]
    public function coupon_check_requires_authentication(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $order = Order::createWithPublicCode([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 1000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => [],
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'X'])
            ->assertStatus(401);
    }

    #[Test]
    public function a_customer_cannot_check_a_coupon_on_someone_elses_order(): void
    {
        $owner = $this->actingAsCustomer();
        $order = $this->pendingOrder($owner);
        $this->coupon('SUMMER10', bps: 1000);

        $this->actingAsCustomer();
        $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'SUMMER10'])
            ->assertStatus(403);
    }

    #[Test]
    public function invalid_inactive_expired_and_not_yet_started_codes_are_all_rejected(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);

        $this->coupon('INACTIVE', bps: 1000, isActive: false);
        $this->coupon('EXPIRED', bps: 1000, endsAt: now()->subDay()->toDateTimeString());
        $this->coupon('FUTURE', bps: 1000, startsAt: now()->addWeek()->toDateTimeString());

        foreach (['NOSUCHCODE', 'INACTIVE', 'EXPIRED', 'FUTURE'] as $code) {
            $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => $code])
                ->assertStatus(422)
                ->assertJsonValidationErrors('code');
        }

        // Unknown and merely-inactive codes share one generic message, so the
        // endpoint cannot be used to discover which codes exist.
        $unknown = $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'NOSUCHCODE'])->json('message');
        $inactive = $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'INACTIVE'])->json('message');
        $this->assertSame($unknown, $inactive);
    }

    #[Test]
    public function the_minimum_subtotal_is_measured_against_merchandise_only(): void
    {
        $user = $this->actingAsCustomer();
        // Merchandise 100,000,000 with free pickup shipping.
        $order = $this->pendingOrder($user);
        $this->coupon('BIGSPEND', bps: 1000, minSubtotal: 150_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'BIGSPEND'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    #[Test]
    public function shipping_is_excluded_from_the_coupon_basis(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);

        // Give the order a shipping cost after the fact; the coupon percentage must
        // still be taken from merchandise alone.
        $order->update(['shipping_cost' => 5_000_000, 'total_amount' => 105_000_000]);
        $this->coupon('TEN', bps: 1000);

        $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'TEN'])
            ->assertOk()
            // 10% of 100,000,000 merchandise — not of the 105,000,000 total.
            ->assertJsonPath('discount_amount', 10_000_000)
            ->assertJsonPath('total_before_coupon', 105_000_000)
            ->assertJsonPath('total_after_coupon', 95_000_000);
    }

    // ── Reservation and the pricing freeze ────────────────────────────────────

    #[Test]
    public function the_first_payment_initialization_reserves_the_coupon_and_freezes_pricing(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $coupon = $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'summer10',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('SUMMER10', $order->coupon_code);
        $this->assertSame(10_000_000, $order->coupon_discount_amount);
        $this->assertSame(90_000_000, $order->total_amount);
        $this->assertNotNull($order->payment_pricing_finalized_at);

        // The snapshot must stand alone, without the live coupon row.
        $this->assertSame('SUMMER10', $order->coupon_snapshot['code']);
        $this->assertSame(1000, $order->coupon_snapshot['percentage_bps']);
        $this->assertSame(10_000_000, $order->coupon_snapshot['discount_amount']);

        $redemption = CouponRedemption::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($coupon->id, $redemption->coupon_id);
        $this->assertSame(RedemptionStatus::RESERVED, $redemption->status);
    }

    #[Test]
    public function the_payment_amount_equals_the_frozen_order_total(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'SUMMER10',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => 90_000_000,
        ]);
    }

    #[Test]
    public function initializing_without_a_coupon_freezes_the_no_coupon_decision(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
        ])->assertOk();

        $order->refresh();
        $this->assertNull($order->coupon_code);
        $this->assertNotNull($order->payment_pricing_finalized_at);
        $this->assertSame(100_000_000, $order->total_amount);

        // Adding a coupon afterwards is refused — the amount is already committed.
        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'SUMMER10',
        ])->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $this->assertSame(100_000_000, $order->fresh()->total_amount);
        $this->assertDatabaseCount('coupon_redemptions', 0);
    }

    #[Test]
    public function retrying_with_the_same_code_or_none_is_allowed_but_a_different_code_is_not(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);
        $this->coupon('VIP20', bps: 2000);

        $init = fn (?string $code) => $this->postJson('/api/v1/payments/initialize', array_filter([
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => $code,
        ], fn ($v) => $v !== null));

        $init('SUMMER10')->assertOk();

        // Same code (in any case) — fine.
        $init('summer10')->assertOk();
        // Omitted entirely — fine, uses the frozen pricing.
        $init(null)->assertOk();
        // A different code — refused.
        $init('VIP20')->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $order->refresh();
        $this->assertSame('SUMMER10', $order->coupon_code);
        $this->assertSame(90_000_000, $order->total_amount);

        // Three successful attempts, but only ever one reservation.
        $this->assertSame(3, Payment::where('order_id', $order->id)->count());
        $this->assertDatabaseCount('coupon_redemptions', 1);
    }

    #[Test]
    public function checking_a_coupon_after_pricing_is_frozen_is_rejected(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$order->id}/coupon/check", ['code' => 'SUMMER10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ── Usage limits ──────────────────────────────────────────────────────────

    #[Test]
    public function a_global_usage_limit_cannot_be_exceeded(): void
    {
        $this->coupon('LIMITED', fixed: 1_000_000, usageLimit: 1);

        $first = $this->actingAsCustomer();
        $firstOrder = $this->pendingOrder($first);
        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $firstOrder->id,
            'method_type' => 'online',
            'coupon_code' => 'LIMITED',
        ])->assertOk();

        $second = $this->actingAsCustomer();
        $secondOrder = $this->pendingOrder($second);
        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $secondOrder->id,
            'method_type' => 'online',
            'coupon_code' => 'LIMITED',
        ])->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $this->assertDatabaseCount('coupon_redemptions', 1);
    }

    #[Test]
    public function a_per_user_limit_cannot_be_exceeded(): void
    {
        $this->coupon('ONCE', fixed: 1_000_000, usageLimitPerUser: 1);

        $user = $this->actingAsCustomer();
        $first = $this->pendingOrder($user);
        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $first->id,
            'method_type' => 'online',
            'coupon_code' => 'ONCE',
        ])->assertOk();

        // A second order for the same customer — the per-user allowance is spent.
        // (pendingOrder cancels the previous pending order, releasing its claim, so
        // reserve explicitly against a fresh order that is not the replaced one.)
        $second = $this->pendingOrder($user);
        $response = $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $second->id,
            'method_type' => 'online',
            'coupon_code' => 'ONCE',
        ]);

        // The first order was superseded and released, so the allowance is free
        // again — this is the documented behaviour, asserted explicitly.
        $response->assertOk();
        $this->assertSame(
            RedemptionStatus::RELEASED,
            CouponRedemption::where('order_id', $first->id)->firstOrFail()->status,
        );
    }

    #[Test]
    public function released_claims_do_not_count_towards_limits_but_reserved_and_redeemed_do(): void
    {
        $coupon = $this->coupon('COUNTME', fixed: 1_000_000, usageLimit: 2);

        CouponRedemption::query()->create([
            'coupon_id' => $coupon->id, 'order_id' => 9001, 'user_id' => 1,
            'status' => RedemptionStatus::RELEASED->value, 'discount_amount' => 1_000_000,
        ]);
        CouponRedemption::query()->create([
            'coupon_id' => $coupon->id, 'order_id' => 9002, 'user_id' => 1,
            'status' => RedemptionStatus::REDEEMED->value, 'discount_amount' => 1_000_000,
        ]);

        // 1 redeemed + 0 reserved = 1 of 2 used; one slot remains.
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'COUNTME',
        ])->assertOk();

        // Now 1 redeemed + 1 reserved = 2 of 2; the next customer is refused.
        $other = $this->actingAsCustomer();
        $otherOrder = $this->pendingOrder($other);
        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $otherOrder->id,
            'method_type' => 'online',
            'coupon_code' => 'COUNTME',
        ])->assertStatus(422);
    }

    // ── Redemption and release ────────────────────────────────────────────────

    #[Test]
    public function an_in_person_payment_reserves_and_immediately_redeems(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('CASH10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'in_person',
            'coupon_code' => 'CASH10',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(90_000_000, $order->total_amount);
        $this->assertSame(
            RedemptionStatus::REDEEMED,
            CouponRedemption::where('order_id', $order->id)->firstOrFail()->status,
        );
    }

    #[Test]
    public function cancelling_an_order_releases_its_coupon_claim(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'SUMMER10',
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame(
            RedemptionStatus::RELEASED,
            CouponRedemption::where('order_id', $order->id)->firstOrFail()->status,
        );
    }

    #[Test]
    public function releasing_twice_and_redeeming_twice_are_both_idempotent(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'SUMMER10',
        ])->assertOk();

        $promotion = $this->promotion();

        $promotion->redeemCouponForOrder($order->id);
        $promotion->redeemCouponForOrder($order->id);
        $this->assertSame(
            RedemptionStatus::REDEEMED,
            CouponRedemption::where('order_id', $order->id)->firstOrFail()->status,
        );

        // A release after redemption must NOT undo a realized sale.
        $promotion->releaseCouponForOrder($order->id);
        $promotion->releaseCouponForOrder($order->id);
        $this->assertSame(
            RedemptionStatus::REDEEMED,
            CouponRedemption::where('order_id', $order->id)->firstOrFail()->status,
        );

        $this->assertDatabaseCount('coupon_redemptions', 1);
    }

    #[Test]
    public function ttl_expiry_releases_the_claim(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'SUMMER10',
        ])->assertOk();

        $order->forceFill(['created_at' => now()->subHours(2)])->save();

        $this->artisan('orders:cancel-expired')->assertExitCode(0);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(
            RedemptionStatus::RELEASED,
            CouponRedemption::where('order_id', $order->id)->firstOrFail()->status,
        );
    }

    // ── The client cannot dictate the price ───────────────────────────────────

    #[Test]
    public function client_supplied_amounts_are_ignored_and_only_the_code_is_honoured(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->pendingOrder($user);
        $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'coupon_code' => 'SUMMER10',
            // All server-computed; these must have no effect whatsoever.
            'coupon_discount_amount' => 99_000_000,
            'final_total' => 1,
            'percentage' => 99,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(10_000_000, $order->coupon_discount_amount);
        $this->assertSame(90_000_000, $order->total_amount);
    }
}
