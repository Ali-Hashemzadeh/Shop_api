<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Analytics\Application\Actions\RecordPaymentStatsAction;
use Modules\Payment\Domain\Events\PaymentCancelledEvent;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Payment\Domain\Events\PaymentSuccessfulEvent;

class RecordPaymentAnalytics implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly RecordPaymentStatsAction $action,
    ) {}

    public function handleSuccess(PaymentSuccessfulEvent $event): void
    {
        $this->action->handleSuccess($event);
    }

    public function handleFailed(PaymentFailedEvent $event): void
    {
        $this->action->handleFailed($event);
    }

    public function handleCancelled(PaymentCancelledEvent $event): void
    {
        $this->action->handleCancelled($event);
    }
}
