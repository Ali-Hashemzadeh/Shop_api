<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Facades\Http;
use Modules\Shipment\Application\Actions\HandShipmentToPostAction;
use Modules\Shipment\Application\Actions\MarkLocalShipmentReadyAction;
use Modules\Shipment\Application\Actions\MarkPostalShipmentReadyAction;
use Modules\Shipment\Application\Actions\MarkShipmentDeliveredAction;
use Modules\Shipment\Application\Actions\MarkShipmentOutForDeliveryAction;
use Modules\Shipment\Application\Actions\StartPreparingShipmentAction;
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

        $payload = [
            'shipment_method_code' => $methodCode,
            'address_id' => $this->createAddress($user->id),
        ];

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

    // ── preparing → SMS only ──────────────────────────────────────────────────

    /** @test */
    public function starting_preparation_sends_an_sms_and_no_in_app_notification(): void
    {
        $shipment = $this->paidShipment('post_standard');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);

        $message = $this->sms->lastMessage();
        $this->assertNotNull($message);
        $this->assertSame('shipment_preparing', $message->template);
        $this->assertSame(['OrderId' => $shipment->order_id], $message->parameters);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_preparing',
        ]);
    }

    // ── handed_to_post / out_for_delivery → in-app + SMS ──────────────────────

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
            'type' => 'shipment_sent',
            'title' => 'ارسال سفارش',
            'message' => 'سفارش شما ارسال شد.',
        ]);

        $message = $this->sms->lastMessage();
        $this->assertSame('shipment_sent', $message->template);
        $this->assertSame(
            ['OrderId' => $shipment->order_id, 'TrackingCode' => 'TRACK-123'],
            $message->parameters
        );
    }

    /** @test */
    public function local_delivery_dispatch_notifies_without_a_tracking_code(): void
    {
        $shipment = $this->paidShipment('local_delivery');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkLocalShipmentReadyAction::class)->handle($shipment->id, $shipment->user_id);
        $this->sms->reset();

        app(MarkShipmentOutForDeliveryAction::class)->handle($shipment->id, $shipment->user_id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_sent',
        ]);

        // No tracking number exists for local delivery, so the parameter is omitted.
        $this->assertSame(['OrderId' => $shipment->order_id], $this->sms->lastMessage()->parameters);
    }

    // ── delivered → in-app + SMS ──────────────────────────────────────────────

    /** @test */
    public function marking_a_local_delivery_delivered_notifies_the_customer(): void
    {
        $shipment = $this->paidShipment('local_delivery');

        app(StartPreparingShipmentAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkLocalShipmentReadyAction::class)->handle($shipment->id, $shipment->user_id);
        app(MarkShipmentOutForDeliveryAction::class)->handle($shipment->id, $shipment->user_id);
        $this->sms->reset();

        app(MarkShipmentDeliveredAction::class)->handle($shipment->id, $shipment->user_id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $shipment->user_id,
            'type' => 'shipment_delivered',
            'title' => 'تحویل سفارش',
            'message' => 'سفارش شما تحویل داده شد.',
        ]);

        $message = $this->sms->lastMessage();
        $this->assertSame('shipment_delivered', $message->template);
        $this->assertSame(['OrderId' => $shipment->order_id], $message->parameters);
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
