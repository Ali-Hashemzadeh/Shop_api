<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Payment\Domain\Events\PaymentCancelledEvent;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Payment\Domain\Events\PaymentSuccessfulEvent;
use Tests\TestCase;

class PaymentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    public function test_payment_successful_event_updates_payment_stats(): void
    {
        Event::dispatch(new PaymentSuccessfulEvent(
            orderId: 10,
            userId: 2,
            gateway: 'zarinpal',
            amount: 500000,
            paidAt: '2026-08-18 12:00:00',
        ));

        $this->assertDatabaseHas('analytics_payment_stats', [
            'date' => '2026-08-18',
            'gateway' => 'zarinpal',
            'successful_count' => 1,
            'failed_count' => 0,
            'cancelled_count' => 0,
            'total_amount' => 500000,
        ]);
    }

    public function test_payment_failed_event_updates_payment_stats(): void
    {
        Event::dispatch(new PaymentFailedEvent(
            orderId: 11,
            userId: 3,
            gateway: 'zarinpal',
            amount: 250000,
        ));

        $this->assertDatabaseHas('analytics_payment_stats', [
            'date' => now()->toDateString(),
            'gateway' => 'zarinpal',
            'successful_count' => 0,
            'failed_count' => 1,
            'cancelled_count' => 0,
            'total_amount' => 0,
        ]);
    }

    public function test_payment_cancelled_event_updates_payment_stats(): void
    {
        Event::dispatch(new PaymentCancelledEvent(
            orderId: 12,
            userId: 4,
            gateway: 'zarinpal',
            amount: 150000,
            cancelledAt: '2026-08-18 14:00:00',
        ));

        $this->assertDatabaseHas('analytics_payment_stats', [
            'date' => '2026-08-18',
            'gateway' => 'zarinpal',
            'successful_count' => 0,
            'failed_count' => 0,
            'cancelled_count' => 1,
            'total_amount' => 0,
        ]);
    }
}
