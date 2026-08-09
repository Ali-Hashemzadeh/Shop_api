<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Notification\Domain\Models\Notification;
use Modules\Shipment\Application\Actions\ConfirmPickupAction;
use Modules\Shipment\Application\Actions\HandShipmentToPostAction;
use Modules\Shipment\Application\Actions\MarkLocalShipmentReadyAction;
use Modules\Shipment\Application\Actions\MarkPickupReadyAction;
use Modules\Shipment\Application\Actions\MarkPostalShipmentReadyAction;
use Modules\Shipment\Application\Actions\MarkShipmentDeliveredAction;
use Modules\Shipment\Application\Actions\MarkShipmentOutForDeliveryAction;
use Modules\Shipment\Application\Actions\StartPreparingShipmentAction;
use Modules\Shipment\Domain\Events\ShipmentReadyForPickupEvent;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;

/**
 * Notifications raised from the existing shipment transitions. Only statuses the
 * workflows already own are covered — no new status is introduced here.
 */
class ShipmentNotificationTest extends ShipmentTestCase
{
    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNotificationPermissions();

        Http::preventStrayRequests();

        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    /** Create a paid order + active shipment for the given method. */
    private function paidShipment(string $methodCode): Shipment
    {
        $user = $this->actingAsOperator();
        $user->forceFill(['phone' => '09121234567'])->save();

        $payload = ['shipment_method_code' => $methodCode];

        // Pickup is address-less by design; every other method needs one.
        if ($methodCode !== 'in_person_pickup') {
            $payload['address_id'] = $this->createAddress($user->id);
        }

        if ($methodCode === 'local_delivery') {
            $payload['delivery_slot_id'] = $this->createBookableSlot()->id;
        }

        $sku = 'NOTIF-'.uniqid();
        $this->createVariantWithStock($sku, 10000, 5);
        $this->addToCart($user->id, $sku, 1);

        $orderId = $this->postJson('/api/v1/orders', $payload)->assertStatus(201)->json('id');
        $this->markOrderPaid($orderId);

        // Paying already produced the payment_success notification + SMS; start
        // each shipment assertion from a clean slate.
        $this->sms->reset();

        return Shipment::where('order_id', $orderId)->firstOrFail();
    }

    /**
     * The order's customer-facing code — what the SMS quotes instead of the
     * internal order id. Read straight from the table so the test does not reach
     * across a module wall for an Order model.
     */
    private function orderCode(Shipment $shipment): string
    {
        $code = (string) DB::table('orders')->where('id', $shipment->order_id)->value('public_code');

        $this->assertMatchesRegularExpression('/^bdo-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $code);

        return $code;
    }

    // ── preparing → SMS only ──────────────────────────────────────────────────

    /** @test */
    public function starting_preparation_sends_an_sms_and_no_in_app_notification(): void
    {
        $shipment = $this->paidShipment('post_standard');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);

        $message = $this->sms->lastMessage();
        $this->assertNotNull($message);
        $this->assertSame('shipment_preparing', $message->template);
        // The SMS quotes the order's public code, not its internal id.
        $this->assertSame(['OrderId' => $this->orderCode($shipment)], $message->parameters);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_preparing',
        ]);
    }

    // ── ready_for_pickup → in-app + SMS ───────────────────────────────────────

    /** @test */
    public function marking_a_pickup_shipment_ready_dispatches_its_own_event(): void
    {
        Event::fake([ShipmentReadyForPickupEvent::class]);

        $shipment = $this->paidShipment('in_person_pickup');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkPickupReadyAction::class)->handle($shipment->id, $shipment->user_id);

        Event::assertDispatched(
            ShipmentReadyForPickupEvent::class,
            fn (ShipmentReadyForPickupEvent $e) => $e->orderId === (int) $shipment->order_id
                && $e->userId === (int) $shipment->user_id
                && $e->orderPublicCode === $this->orderCode($shipment),
        );
    }

    /** @test */
    public function a_pickup_shipment_ready_for_collection_notifies_the_customer(): void
    {
        $shipment = $this->paidShipment('in_person_pickup');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        $this->sms->reset();

        app(MarkPickupReadyAction::class)->handle($shipment->id, $shipment->user_id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_ready_for_pickup',
            'title' => 'آماده تحویل حضوری',
            'message' => 'سفارش شما آماده تحویل حضوری است.',
        ]);

        $message = $this->sms->lastMessage();
        $this->assertSame('shipment_ready_for_pickup', $message->template);
        $this->assertSame(['OrderId' => $this->orderCode($shipment)], $message->parameters);
    }

    /** @test */
    public function collecting_a_pickup_order_does_not_repeat_the_ready_notification(): void
    {
        $shipment = $this->paidShipment('in_person_pickup');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkPickupReadyAction::class)->handle($shipment->id, $shipment->user_id);
        $this->sms->reset();

        app(ConfirmPickupAction::class)->handle($shipment->id, (int) $shipment->user_id);

        // The customer is standing at the counter: nothing more to say.
        $this->assertSame(
            1,
            Notification::where('user_id', $shipment->user_id)
                ->where('type', 'shipment_ready_for_pickup')
                ->count(),
        );
        $this->assertSame([], $this->sms->sent());
    }

    // ── handed_to_post → in-app + SMS ─────────────────────────────────────────

    /** @test */
    public function handing_a_postal_shipment_to_post_notifies_with_the_tracking_code(): void
    {
        $shipment = $this->paidShipment('post_standard');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkPostalShipmentReadyAction::class)->handle($shipment->id, $shipment->user_id);
        $this->sms->reset();

        app(HandShipmentToPostAction::class)->handle($shipment->id, $shipment->user_id, 'TRACK-123');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_handed_to_post',
            'title' => 'تحویل به پست',
            'message' => 'سفارش شما به شرکت پست تحویل داده شد.',
        ]);

        $message = $this->sms->lastMessage();
        $this->assertSame('shipment_handed_to_post', $message->template);
        $this->assertSame(
            ['OrderId' => $this->orderCode($shipment), 'TrackingCode' => 'TRACK-123'],
            $message->parameters
        );
    }

    /** @test */
    public function a_postal_handoff_never_uses_the_local_delivery_template(): void
    {
        $shipment = $this->paidShipment('post_standard');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkPostalShipmentReadyAction::class)->handle($shipment->id, $shipment->user_id);
        $this->sms->reset();

        app(HandShipmentToPostAction::class)->handle($shipment->id, $shipment->user_id, 'TRACK-999');

        $templates = array_map(fn ($m) => $m->template, $this->sms->sent());
        $this->assertNotContains('shipment_out_for_delivery', $templates);
        // And the retired generic template is gone from the postal path too.
        $this->assertNotContains('shipment_sent', $templates);
        // No code parameter can leak into a flow that has no code.
        $this->assertArrayNotHasKey('DeliveryCode', $this->sms->lastMessage()->parameters);
    }

    // ── out_for_delivery → in-app + SMS ───────────────────────────────────────

    /** @test */
    public function local_delivery_dispatch_sends_one_sms_carrying_the_handoff_code(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkLocalShipmentReadyAction::class)->handle($shipment->id, $shipment->user_id);
        $this->assignDriver($shipment->id, $driver, $shipment->user_id);
        $this->sms->reset();

        $handoffCode = $this->captureDeliveryCode(
            fn () => app(MarkShipmentOutForDeliveryAction::class)->handle($shipment->id, $shipment->user_id),
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_out_for_delivery',
            'title' => 'ارسال سفارش',
            'message' => 'سفارش شما برای تحویل ارسال شد.',
        ]);

        // Exactly one message to the customer — the dispatch SMS *is* the code SMS.
        $customerMessages = array_values(array_filter(
            $this->sms->sent(),
            fn ($m) => $m->receiver === '09121234567',
        ));
        $this->assertCount(1, $customerMessages);

        $message = $customerMessages[0];
        // A code-bearing template, and no tracking parameter: local delivery has none.
        $this->assertSame('shipment_out_for_delivery', $message->template);
        $this->assertSame(
            ['OrderId' => $this->orderCode($shipment), 'DeliveryCode' => $handoffCode],
            $message->parameters,
        );
    }

    /** @test */
    public function the_stored_dispatch_notification_never_contains_the_handoff_code(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();

        $handoffCode = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $shipment->user_id);

        $notification = Notification::where('user_id', $shipment->user_id)
            ->where('type', 'shipment_out_for_delivery')
            ->firstOrFail();

        $serialized = json_encode([$notification->data, $notification->title, $notification->message], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($handoffCode, (string) $serialized);
        $this->assertSame(
            ['order_id' => (int) $shipment->order_id, 'order_public_code' => $this->orderCode($shipment)],
            $notification->data,
        );

        // Nor is the hash exposed — only the shipment row holds it.
        $this->assertArrayNotHasKey('delivery_verification_code_hash', $notification->data);
    }

    // ── delivered → in-app + SMS ──────────────────────────────────────────────

    /** @test */
    public function marking_a_local_delivery_delivered_notifies_the_customer(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();

        $handoffCode = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $shipment->user_id);
        $this->sms->reset();

        app(MarkShipmentDeliveredAction::class)->handle(
            shipmentId: $shipment->id,
            operatorId: (int) $shipment->user_id,
            code: $handoffCode,
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_delivered',
            'title' => 'تحویل سفارش',
            'message' => 'سفارش شما تحویل داده شد.',
        ]);

        $message = $this->sms->lastMessage();
        $this->assertSame('shipment_delivered', $message->template);
        $this->assertSame(['OrderId' => $this->orderCode($shipment)], $message->parameters);
    }

    // ── unconfigured template must not break fulfillment ──────────────────────

    /** @test */
    public function a_missing_shipment_template_does_not_break_the_transition(): void
    {
        $shipment = $this->paidShipment('post_standard');

        config()->set('sms.default', 'smsir');
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.shipment_preparing', null);

        $dto = app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);

        $this->assertSame('preparing', $dto->status->value);
        $this->assertDatabaseHas('shipments', ['id' => $shipment->id, 'status' => 'preparing']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'skipped']);
    }
}
