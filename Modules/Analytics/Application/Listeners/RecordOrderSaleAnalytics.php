<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Analytics\Application\Actions\RecordOrderSaleAction;
use Modules\Order\Domain\Events\OrderPaidEvent;

class RecordOrderSaleAnalytics implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly RecordOrderSaleAction $action,
    ) {}

    public function handle(OrderPaidEvent $event): void
    {
        $this->action->handle($event);
    }
}
