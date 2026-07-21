<?php

namespace Tests\Feature\Sms;

use Illuminate\Support\Facades\Http;
use Modules\Sms\Domain\Contracts\SmsManagerInterface;
use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\Exceptions\UnknownSmsProviderException;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Modules\Sms\Infrastructure\Drivers\SmsIrProvider;
use Modules\Sms\Infrastructure\Services\SmsProviderFactory;
use Tests\TestCase;

/**
 * Provider abstraction coverage. Every test runs under
 * Http::preventStrayRequests(), so an unfaked outbound call fails the suite —
 * no test can reach a real SMS API.
 */
class SmsManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function manager(): SmsManagerInterface
    {
        return app(SmsManagerInterface::class);
    }

    // ── Provider resolution ───────────────────────────────────────────────────

    /** @test */
    public function the_manager_resolves_the_configured_provider(): void
    {
        config()->set('sms.default', 'fake');
        $fake = app(FakeSmsProvider::class);
        $fake->reset();

        $result = $this->manager()->send(new SmsMessageDTO('09121234567', 'payment_success', ['OrderId' => 1]));

        $this->assertTrue($result->success);
        $this->assertSame('fake', $result->provider);
        $this->assertSame('fake', $this->manager()->providerName());
        $this->assertCount(1, $fake->sent());
    }

    /** @test */
    public function switching_the_configured_provider_switches_the_driver(): void
    {
        config()->set('sms.default', 'log');
        app(FakeSmsProvider::class)->reset();

        $result = $this->manager()->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertTrue($result->success);
        $this->assertSame('log', $result->provider);
        $this->assertSame([], app(FakeSmsProvider::class)->sent());
    }

    /** @test */
    public function the_factory_rejects_an_unknown_provider(): void
    {
        $this->expectException(UnknownSmsProviderException::class);

        app(SmsProviderFactory::class)->make('carrier-pigeon');
    }

    /** @test */
    public function an_unknown_configured_provider_degrades_to_a_failed_result(): void
    {
        config()->set('sms.default', 'carrier-pigeon');

        $result = $this->manager()->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unknown SMS provider', (string) $result->error);
    }

    // ── SMS.ir payload translation ────────────────────────────────────────────

    /** @test */
    public function the_smsir_provider_converts_the_internal_message_to_its_own_format(): void
    {
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.payment_success', 424242);

        Http::fake([
            'api.sms.ir/*' => Http::response(['status' => 1, 'message' => 'موفق', 'data' => ['messageId' => 987]], 200),
        ]);

        $result = app(SmsIrProvider::class)
            ->send(new SmsMessageDTO('09121234567', 'payment_success', ['OrderId' => 123]));

        $this->assertTrue($result->success);
        $this->assertSame('smsir', $result->provider);
        $this->assertSame('987', $result->reference);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.sms.ir/v1/send/verify'
                && $request->hasHeader('X-API-KEY', 'test-key')
                && $request['mobile'] === '989121234567'
                && $request['templateId'] === 424242
                && $request['parameters'] === [['name' => 'OrderId', 'value' => '123']];
        });
    }

    /** @test */
    public function the_smsir_provider_reports_a_transport_failure(): void
    {
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.payment_success', 424242);

        Http::fake(['api.sms.ir/*' => Http::response('service unavailable', 503)]);

        $result = app(SmsIrProvider::class)->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('503', (string) $result->error);
    }

    /** @test */
    public function the_smsir_provider_reports_an_application_level_rejection(): void
    {
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.payment_success', 424242);

        Http::fake(['api.sms.ir/*' => Http::response(['status' => 0, 'message' => 'invalid template'], 200)]);

        $result = app(SmsIrProvider::class)->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('invalid template', (string) $result->error);
    }

    // ── Optional templates ────────────────────────────────────────────────────

    /** @test */
    public function a_configured_template_is_sent(): void
    {
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.payment_success', 424242);

        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 5]], 200)]);

        $result = app(SmsIrProvider::class)->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertTrue($result->success);
        $this->assertFalse($result->skipped);
        Http::assertSentCount(1);
    }

    /** @test */
    public function a_missing_template_id_skips_the_sms_without_throwing_or_calling_the_provider(): void
    {
        // preventStrayRequests() with no fake registered: any outbound call fails the test.
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.payment_success', null);

        $result = app(SmsIrProvider::class)->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertTrue($result->skipped);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('template id', (string) $result->error);
    }

    /** @test */
    public function a_missing_template_id_never_throws_through_the_manager(): void
    {
        config()->set('sms.default', 'smsir');
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.order_cancelled', null);

        $result = $this->manager()->send(new SmsMessageDTO('09121234567', 'order_cancelled', ['OrderId' => 3]));

        $this->assertTrue($result->skipped);
        $this->assertSame('smsir', $result->provider);
    }

    /** @test */
    public function missing_credentials_skip_the_sms_rather_than_failing_it(): void
    {
        config()->set('sms.providers.smsir.api_key', '');
        config()->set('sms.providers.smsir.templates.payment_success', 424242);

        $result = app(SmsIrProvider::class)->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('api key', (string) $result->error);
    }

    /** @test */
    public function a_skip_is_not_a_failure_a_rejection_is(): void
    {
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.payment_success', 424242);

        Http::fake(['api.sms.ir/*' => Http::response(['status' => 0, 'message' => 'rejected'], 200)]);

        $rejected = app(SmsIrProvider::class)->send(new SmsMessageDTO('09121234567', 'payment_success'));

        $this->assertFalse($rejected->success);
        $this->assertFalse($rejected->skipped);
    }

    // ── Test double ───────────────────────────────────────────────────────────

    /** @test */
    public function the_fake_provider_records_messages_and_can_simulate_failure(): void
    {
        config()->set('sms.default', 'fake');
        $fake = app(FakeSmsProvider::class);
        $fake->reset();

        $this->manager()->send(new SmsMessageDTO('09120000001', 'shipment_sent', ['OrderId' => 5]));

        $this->assertCount(1, $fake->sent());
        $this->assertSame('shipment_sent', $fake->lastMessage()->template);

        $fake->shouldFail = true;
        $result = $this->manager()->send(new SmsMessageDTO('09120000001', 'shipment_sent'));

        $this->assertFalse($result->success);
        $this->assertCount(2, $fake->sent());
    }
}
