<?php

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Notification\Domain\Models\Notification;
use Modules\Order\Application\Actions\CancelOrderAction;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\Events\OrderCancelledEvent;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Modules\Payment\Application\Actions\HandleZarinpalCallbackAction;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Payment\Domain\Models\Payment;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Tests\TestCase;

/**
 * Business flow → event → notification listener → channels.
 *
 * Covers the wiring only: the notification/SMS infrastructure itself is tested
 * in NotificationDispatchTest and SmsManagerTest.
 */
class NotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
        $this->seedOrderPermissions();
        $this->seedPaymentPermissions();
        $this->seedNotificationPermissions();

        Http::preventStrayRequests();

        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createOrder(int $userId, string $sku = 'NOTIF-SKU'): Order
    {
        $order = Order::create([
            'user_id' => $userId,
            'status' => 'pending',
            'total_amount' => 250000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'Test Street'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku' => $sku,
            'quantity' => 1,
            'price_per_unit' => 250000,
            'line_total' => 250000,
            'product_title' => 'Test product',
        ]);

        // Reserved stock so commit/release during pay/cancel have something to act on.
        $inventory = app(InventoryManagerInterface::class);
        $inventory->adjustStock($sku, 5, 'restock');
        $inventory->reserveStock($sku, 1, $order->id);

        return $order->fresh('items');
    }

    private function createInitiatedPayment(int $orderId, string $authority): Payment
    {
        return Payment::create([
            'order_id' => $orderId,
            'method_type' => 'online',
            'gateway' => 'zarinpal',
            'transaction_reference' => $authority,
            'amount' => 250000,
            'status' => 'initiated',
        ]);
    }

    private function orders(): OrderManagerInterface
    {
        return app(OrderManagerInterface::class);
    }

    // ── Order paid ────────────────────────────────────────────────────────────

    /** @test */
    public function a_successful_mark_as_paid_dispatches_the_order_paid_event(): void
    {
        Event::fake([OrderPaidEvent::class]);

        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        $this->orders()->markAsPaid($order->id, 'REF-1');

        Event::assertDispatched(OrderPaidEvent::class, function (OrderPaidEvent $event) use ($order, $user) {
            return $event->orderId === $order->id
                && $event->userId === $user->id
                && $event->totalAmount === 250000;
        });
    }

    /** @test */
    public function order_paid_creates_the_customer_in_app_notification(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        $this->orders()->markAsPaid($order->id, 'REF-1');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'payment_success',
            'title' => 'پرداخت موفق',
            'message' => 'پرداخت سفارش شما با موفقیت انجام شد.',
        ]);
    }

    /** @test */
    public function order_paid_attempts_the_sms_with_our_internal_parameter_names(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        $this->orders()->markAsPaid($order->id, 'REF-1');

        $message = $this->sms->lastMessage();
        $this->assertNotNull($message);
        $this->assertSame('payment_success', $message->template);
        $this->assertSame('09121234567', $message->receiver);
        $this->assertSame(['OrderId' => $order->id], $message->parameters);

        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'sent']);
    }

    /** @test */
    public function a_missing_sms_template_does_not_break_the_payment_flow(): void
    {
        // Real provider, credentials present, template id absent → skip.
        config()->set('sms.default', 'smsir');
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.payment_success', null);

        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        $dto = $this->orders()->markAsPaid($order->id, 'REF-1');

        $this->assertSame('paid', $dto->status->value);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'payment_success']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'skipped']);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'failed']);
    }

    /** @test */
    public function a_repeated_mark_as_paid_does_not_duplicate_notifications(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        $this->orders()->markAsPaid($order->id, 'REF-1');
        $this->orders()->markAsPaid($order->id, 'REF-1');
        $this->orders()->markAsPaid($order->id, 'REF-1');

        $this->assertSame(
            1,
            Notification::where('user_id', $user->id)->where('type', 'payment_success')->count()
        );
        $this->assertCount(1, $this->sms->sent());
    }

    /** @test */
    public function order_paid_creates_the_admin_in_app_notification(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        $this->orders()->markAsPaid($order->id, 'REF-1');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'admin_order_paid',
            'title' => 'سفارش پرداخت شد',
            'message' => "سفارش شماره {$order->id} پرداخت شد.",
        ]);

        // Admin notification is in-app only — no second SMS.
        $this->assertCount(1, $this->sms->sent());
    }

    // ── Payment failed ────────────────────────────────────────────────────────

    /** @test */
    public function a_failed_verification_dispatches_the_payment_failed_event(): void
    {
        Event::fake([PaymentFailedEvent::class]);

        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);
        $payment = $this->createInitiatedPayment($order->id, 'AUTH-FAIL');

        Http::fake(['*zarinpal*' => Http::response(['data' => ['code' => -51]], 200)]);

        app(HandleZarinpalCallbackAction::class)->handle('OK', $payment->transaction_reference);

        Event::assertDispatched(PaymentFailedEvent::class, function (PaymentFailedEvent $event) use ($order, $user) {
            return $event->orderId === $order->id && $event->userId === $user->id;
        });
    }

    /** @test */
    public function payment_failed_creates_an_in_app_notification_and_no_sms(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);
        $payment = $this->createInitiatedPayment($order->id, 'AUTH-FAIL');

        Http::fake(['*zarinpal*' => Http::response(['data' => ['code' => -51]], 200)]);

        app(HandleZarinpalCallbackAction::class)->handle('OK', $payment->transaction_reference);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'payment_failed',
            'title' => 'پرداخت ناموفق',
            'message' => 'پرداخت سفارش شما ناموفق بود.',
        ]);

        $this->assertSame([], $this->sms->sent());
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    // ── Order cancelled ───────────────────────────────────────────────────────

    /** @test */
    public function a_successful_cancellation_dispatches_the_order_cancelled_event(): void
    {
        Event::fake([OrderCancelledEvent::class]);

        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        app(CancelOrderAction::class)->handle($order->id, $user->id);

        Event::assertDispatched(OrderCancelledEvent::class, function (OrderCancelledEvent $event) use ($order, $user) {
            return $event->orderId === $order->id && $event->userId === $user->id;
        });
    }

    /** @test */
    public function order_cancelled_creates_the_customer_notification_and_attempts_sms(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        app(CancelOrderAction::class)->handle($order->id, $user->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'order_cancelled',
            'title' => 'لغو سفارش',
            'message' => 'سفارش شما لغو شد.',
        ]);

        $message = $this->sms->lastMessage();
        $this->assertNotNull($message);
        $this->assertSame('order_cancelled', $message->template);
        $this->assertSame(['OrderId' => $order->id], $message->parameters);
    }

    /** @test */
    public function the_internal_release_and_cancel_primitive_notifies_nobody(): void
    {
        Event::fake([OrderCancelledEvent::class]);

        $user = User::factory()->create(['phone' => '09121234567']);
        $order = $this->createOrder($user->id);

        // Used by checkout to retire a superseded pending order — the customer
        // never asked for it, so it must stay silent.
        app(CancelOrderAction::class)->releaseAndCancel($order);

        Event::assertNotDispatched(OrderCancelledEvent::class);
    }
}
