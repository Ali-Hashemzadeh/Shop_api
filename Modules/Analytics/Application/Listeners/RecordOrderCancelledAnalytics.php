<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Analytics\Application\Actions\RecordOrderCancellationAction;
use Modules\Order\Domain\Events\OrderCancelledEvent;

class RecordOrderCancelledAnalytics implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly RecordOrderCancellationAction $action,
    ) {}

    public function handle(OrderCancelledEvent $event): void
    {
        $this->action->handle($event);
    }
}
