<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Identity\Domain\Models\User;
use Modules\Order\Domain\Models\Order;
use Modules\Payment\Domain\Models\Payment;
use Modules\Payment\Infrastructure\Gateways\MockGatewayDriver;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedOrderPermissions();
        $this->seedPaymentPermissions();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createPendingOrder(int $userId, int $totalAmount = 100000): Order
    {
        return Order::create([
            'user_id' => $userId,
            'status' => 'pending',
            'total_amount' => $totalAmount,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'Test Street'],
        ]);
    }

    private function createInitiatedPayment(int $orderId, string $authority): Payment
    {
        return Payment::create([
            'order_id' => $orderId,
            'method_type' => 'online',
            'gateway' => 'mock',
            'transaction_reference' => $authority,
            'amount' => 100000,
            'status' => 'initiated',
        ]);
    }

    private function createCartItem(int $userId, string $sku = 'PAYMENT-CART-ITEM'): CartItem
    {
        $cart = Cart::create(['user_id' => $userId]);

        return CartItem::create(['cart_id' => $cart->id, 'sku' => $sku, 'quantity' => 1]);
    }

    // ── Scenario 1: In-person — instant cash checkout ─────────────────────────

    /** @test */
    public function it_initializes_in_person_payment_creates_ledger_row_and_marks_order_paid(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id, 150000);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'in_person',
        ])
            ->assertStatus(200)
            ->assertJsonPath('type', 'in_person')
            ->assertJsonPath('status', 'pending_cash')
            ->assertJsonPath('redirect_url', null);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method_type' => 'in_person',
            'status' => 'pending_cash',
            'amount' => 150000,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
        ]);
    }

    // ── Scenario 2: Online — initialize returns gateway redirect URL ──────────

    /** @test */
    public function it_initializes_online_payment_and_returns_redirect_url(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id, 200000);

        $response = $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'gateway' => 'mock',
        ])
            ->assertStatus(200)
            ->assertJsonPath('type', 'online')
            ->assertJsonPath('status', 'initiated')
            ->assertJsonStructure(['redirect_url', 'payment_id']);

        $this->assertStringContainsString('mock-gateway.test', $response->json('redirect_url'));

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method_type' => 'online',
            'gateway' => 'mock',
            'status' => 'initiated',
            'amount' => 200000,
        ]);
    }

    // ── Scenario 3: Callback — successful verify captures payment ─────────────

    /** @test */
    public function it_captures_payment_and_marks_order_paid_on_successful_callback(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id, 100000);
        $cartItem = $this->createCartItem($user->id);

        $driver = new MockGatewayDriver;
        $this->app->instance(MockGatewayDriver::class, $driver);

        $authority = 'TEST-AUTH-SUCCESS-001';
        $this->createInitiatedPayment($order->id, $authority);

        $this->get("/api/v1/payments/zarinpal/callback?Status=OK&Authority={$authority}")
            ->assertStatus(200)
            ->assertViewIs('payment::payment')
            ->assertViewHas('success', true)
            ->assertViewHas('gateway', 'mock')
            ->assertViewHas('trackId', $driver->fakeRefId);

        $this->assertDatabaseHas('payments', [
            'transaction_reference' => $driver->fakeRefId,
            'status' => 'captured',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'transaction_ref' => $driver->fakeRefId,
        ]);
        $this->assertDatabaseMissing('cart_items', ['id' => $cartItem->id]);
    }

    // ── Scenario 4: Callback — user cancelled at bank ─────────────────────────

    /** @test */
    public function it_marks_payment_failed_when_user_cancels_at_gateway(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id, 100000);
        $cartItem = $this->createCartItem($user->id);

        $authority = 'TEST-AUTH-CANCEL-001';
        $this->createInitiatedPayment($order->id, $authority);

        $this->get("/api/v1/payments/zarinpal/callback?Status=NOK&Authority={$authority}")
            ->assertStatus(200)
            ->assertViewIs('payment::payment')
            ->assertViewHas('success', false);

        $this->assertDatabaseHas('payments', [
            'transaction_reference' => $authority,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    // ── Scenario 5: Callback — gateway verification rejects ───────────────────

    /** @test */
    public function it_marks_payment_failed_when_gateway_verification_rejects(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id, 100000);
        $cartItem = $this->createCartItem($user->id);

        $driver = new MockGatewayDriver;
        $driver->shouldVerifySucceed = false;
        $this->app->instance(MockGatewayDriver::class, $driver);

        $authority = 'TEST-AUTH-VERIFY-FAIL-001';
        $this->createInitiatedPayment($order->id, $authority);

        $this->get("/api/v1/payments/zarinpal/callback?Status=OK&Authority={$authority}")
            ->assertStatus(200)
            ->assertViewIs('payment::payment')
            ->assertViewHas('success', false);

        $this->assertDatabaseHas('payments', [
            'transaction_reference' => $authority,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);

        // Failed verification must not commit inventory or activate a shipment
        // (both happen only inside the shared markAsPaid path, never reached here).
        $this->assertDatabaseCount('shipments', 0);
    }

    // ── Scenario 6: Idempotency — already captured skips re-processing ────────

    /** @test */
    public function it_skips_processing_when_payment_already_captured(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id, 100000);

        $authority = 'TEST-AUTH-IDEM-001';
        $payment = Payment::create([
            'order_id' => $order->id,
            'method_type' => 'online',
            'gateway' => 'mock',
            'transaction_reference' => $authority,
            'amount' => 100000,
            'status' => 'captured',
        ]);

        $this->get("/api/v1/payments/zarinpal/callback?Status=OK&Authority={$authority}")
            ->assertStatus(200)
            ->assertViewIs('payment::payment')
            ->assertViewHas('success', true);

        // Idempotent: the already-captured guard short-circuits, so the order is
        // not re-marked paid and no second capture is attempted.
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'captured']);
    }

    // ── Auth & validation matrix ──────────────────────────────────────────────

    /** @test */
    public function unauthenticated_initialize_returns_401(): void
    {
        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => 1,
            'method_type' => 'in_person',
        ])->assertStatus(401);
    }

    /** @test */
    public function validation_fails_when_required_fields_are_missing(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/payments/initialize', [])->assertStatus(422);
    }

    /** @test */
    public function validation_fails_for_invalid_method_type(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id);

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'carrier_pigeon',
        ])->assertStatus(422);
    }

    /** @test */
    public function callback_renders_generic_failure_page_when_authority_is_missing(): void
    {
        // No Authority → no Payment can be resolved → safe generic failure page,
        // never a JSON error or a leaked internal message. Unauthenticated by
        // design (the gateway calls this endpoint without a user session).
        $this->get('/api/v1/payments/zarinpal/callback?Status=OK')
            ->assertStatus(200)
            ->assertViewIs('payment::payment')
            ->assertViewHas('success', false);
    }

    /** @test */
    public function callback_page_links_use_configured_frontend_url_and_real_order(): void
    {
        config()->set('frontend.url', 'https://shop.example.com');
        config()->set('frontend.order_path', 'orders');

        $user = $this->actingAsCustomer();
        $order = $this->createPendingOrder($user->id, 100000);
        $this->createCartItem($user->id);

        $driver = new MockGatewayDriver;
        $this->app->instance(MockGatewayDriver::class, $driver);

        $authority = 'TEST-AUTH-URLS-001';
        $this->createInitiatedPayment($order->id, $authority);

        // An attacker-supplied query param must never influence navigation URLs.
        $this->get("/api/v1/payments/zarinpal/callback?Status=OK&Authority={$authority}&frontend=https://evil.test")
            ->assertStatus(200)
            ->assertViewHas('frontendHomeUrl', 'https://shop.example.com')
            ->assertViewHas('frontendOrderUrl', "https://shop.example.com/orders/{$order->id}")
            ->assertDontSee('evil.test');
    }

    /** @test */
    public function callback_shows_neutral_placeholder_and_no_internal_message_when_unresolved(): void
    {
        // Unknown Authority resolves to no Payment: neutral placeholders, no
        // internal exception/message leakage. Runs unauthenticated on purpose.
        $this->get('/api/v1/payments/zarinpal/callback?Status=OK&Authority=UNKNOWN-AUTH-XYZ')
            ->assertStatus(200)
            ->assertViewIs('payment::payment')
            ->assertViewHas('success', false)
            ->assertViewHas('trackId', null)
            ->assertSee('نامشخص')
            ->assertDontSee('Payment record not found')
            ->assertDontSee('Exception');
    }

    /** @test */
    public function callback_page_loads_the_module_css_asset(): void
    {
        $this->get('/api/v1/payments/zarinpal/callback?Status=OK&Authority=UNKNOWN-AUTH-XYZ')
            ->assertStatus(200)
            ->assertSee('modules/payment/app.css', false);
    }

    /** @test */
    public function it_forbids_initializing_payment_for_another_users_order(): void
    {
        $owner = User::factory()->create();
        $order = $this->createPendingOrder($owner->id, 100000);

        $this->actingAsCustomer(); // a different authenticated user

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method_type' => 'in_person',
        ])->assertStatus(403);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);
    }

    /** @test */
    public function initialize_returns_404_for_nonexistent_order(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/payments/initialize', [
            'order_id' => 99999,
            'method_type' => 'in_person',
        ])->assertStatus(404);
    }
}
