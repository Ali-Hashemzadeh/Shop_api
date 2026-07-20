<?php

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Identity\Domain\Models\User;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\DTOs\SmsPayloadDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationTemplate;
use Modules\Notification\Domain\Models\NotificationDelivery;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Tests\TestCase;

/**
 * Infrastructure-level coverage for the channel fan-out. Business events
 * (OrderPaid, PaymentFailed, …) are a later phase and are deliberately absent.
 */
class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedNotificationPermissions();

        // No test may ever reach a real SMS endpoint.
        Http::preventStrayRequests();

        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    private function manager(): NotificationManagerInterface
    {
        return app(NotificationManagerInterface::class);
    }

    /** @test */
    public function it_stores_an_in_app_notification_and_sends_an_sms(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);

        $dto = $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'payment_success',
            title: 'پرداخت موفق',
            message: 'پرداخت سفارش شما با موفقیت انجام شد.',
            data: ['order_id' => 123],
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::PAYMENT_SUCCESS, ['OrderId' => 123]),
        ));

        $this->assertNotNull($dto);
        $this->assertDatabaseHas('notifications', [
            'id' => $dto->id,
            'user_id' => $user->id,
            'type' => 'payment_success',
            'read_at' => null,
        ]);

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $dto->id,
            'channel' => 'sms',
            'status' => 'sent',
            'provider' => 'fake',
            'error' => null,
        ]);

        $message = $this->sms->lastMessage();
        $this->assertSame('09121234567', $message->receiver);
        $this->assertSame('payment_success', $message->template);
        $this->assertSame(['OrderId' => 123], $message->parameters);
    }

    /** @test */
    public function an_in_app_only_notification_sends_no_sms(): void
    {
        $user = User::factory()->create();

        $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'payment_failed',
            title: 'پرداخت ناموفق',
            message: 'پرداخت سفارش شما ناموفق بود.',
            channels: [NotificationChannel::DATABASE],
        ));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertSame([], $this->sms->sent());
    }

    /** @test */
    public function an_sms_only_notification_creates_no_in_app_record(): void
    {
        $user = User::factory()->create(['phone' => '09121112233']);

        $dto = $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'shipment_preparing',
            title: 'آماده‌سازی سفارش',
            message: 'سفارش شما در حال آماده‌سازی است.',
            channels: [NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_PREPARING, ['OrderId' => 7]),
        ));

        $this->assertNull($dto);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => null,
            'channel' => 'sms',
            'status' => 'sent',
        ]);
    }

    // ── Optional templates ────────────────────────────────────────────────────

    /** @test */
    public function an_unconfigured_template_skips_the_sms_without_throwing(): void
    {
        // A real provider with credentials but no template id for this message.
        // preventStrayRequests() proves no HTTP call is attempted.
        config()->set('sms.default', 'smsir');
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.shipment_delivered', null);

        $user = User::factory()->create(['phone' => '09121234567']);

        $dto = $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'shipment_delivered',
            title: 'تحویل سفارش',
            message: 'سفارش شما تحویل داده شد.',
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_DELIVERED, ['OrderId' => 11]),
        ));

        // The business-facing part of the notification is untouched.
        $this->assertNotNull($dto);
        $this->assertDatabaseHas('notifications', ['id' => $dto->id, 'type' => 'shipment_delivered']);

        $delivery = NotificationDelivery::first();
        $this->assertSame('skipped', $delivery->status);
        $this->assertSame('smsir', $delivery->provider);
        $this->assertStringContainsString('template id', (string) $delivery->error);
        $this->assertNull($delivery->sent_at);
        $this->assertNull($delivery->failed_at);
    }

    /** @test */
    public function a_skipped_sms_is_never_recorded_as_a_failure(): void
    {
        $this->sms->shouldSkip = true;
        $user = User::factory()->create(['phone' => '09121234567']);

        $dto = $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'order_cancelled',
            title: 'لغو سفارش',
            message: 'سفارش شما لغو شد.',
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::ORDER_CANCELLED, ['OrderId' => 55]),
        ));

        $this->assertNotNull($dto);
        $this->assertSame([], $this->sms->sent());
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $dto->id,
            'status' => 'skipped',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'failed']);
    }

    /** @test */
    public function a_failed_sms_provider_response_creates_a_failed_delivery_record(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);
        $this->sms->shouldFail = true;
        $this->sms->failureMessage = 'provider rejected the message';

        $dto = $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'order_cancelled',
            title: 'لغو سفارش',
            message: 'سفارش شما لغو شد.',
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::ORDER_CANCELLED, ['OrderId' => 55]),
        ));

        // The in-app notification still lands — SMS failure never breaks the flow.
        $this->assertNotNull($dto);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $dto->id,
            'channel' => 'sms',
            'status' => 'failed',
            'provider' => 'fake',
            'error' => 'provider rejected the message',
        ]);

        $delivery = NotificationDelivery::first();
        $this->assertNull($delivery->sent_at);
        $this->assertNotNull($delivery->failed_at);
    }

    /** @test */
    public function a_recipient_without_a_phone_number_skips_the_sms(): void
    {
        $user = User::factory()->create(['phone' => null]);

        $dto = $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'shipment_sent',
            title: 'ارسال سفارش',
            message: 'سفارش شما ارسال شد.',
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::SHIPMENT_SENT, ['OrderId' => 9]),
        ));

        $this->assertNotNull($dto);
        $this->assertSame([], $this->sms->sent());
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $dto->id,
            'channel' => 'sms',
            'status' => 'skipped',
        ]);
    }

    /** @test */
    public function requesting_the_sms_channel_without_a_payload_fails_the_delivery_only(): void
    {
        $user = User::factory()->create(['phone' => '09121234567']);

        $dto = $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'shipment_delivered',
            title: 'تحویل سفارش',
            message: 'سفارش شما تحویل داده شد.',
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: null,
        ));

        $this->assertNotNull($dto);
        $this->assertSame([], $this->sms->sent());
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $dto->id,
            'channel' => 'sms',
            'status' => 'failed',
        ]);
    }

    /** @test */
    public function the_stored_notification_is_immediately_readable_through_the_api(): void
    {
        $user = $this->actingAsCustomer(User::factory()->create(['phone' => '09121234567']));

        $this->manager()->send(new NotificationRequestDTO(
            userId: $user->id,
            type: 'payment_success',
            title: 'پرداخت موفق',
            message: 'پرداخت سفارش شما با موفقیت انجام شد.',
            data: ['order_id' => 123],
            channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
            sms: new SmsPayloadDTO(NotificationTemplate::PAYMENT_SUCCESS, ['OrderId' => 123]),
        ));

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'payment_success')
            ->assertJsonPath('data.0.data.order_id', 123);
    }
}
