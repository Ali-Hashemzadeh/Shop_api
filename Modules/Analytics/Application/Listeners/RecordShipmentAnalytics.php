<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Analytics\Application\Actions\RecordShipmentStatsAction;
use Modules\Shipment\Domain\Events\ShipmentAssignedToDeliveryEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveryFailedEvent;
use Modules\Shipment\Domain\Events\ShipmentHandedToPostEvent;

class RecordShipmentAnalytics implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly RecordShipmentStatsAction $action,
    ) {}

    public function handleAssigned(ShipmentAssignedToDeliveryEvent $event): void
    {
        $this->action->handleAssigned($event);
    }

    public function handleHandedToPost(ShipmentHandedToPostEvent $event): void
    {
        $this->action->handleHandedToPost($event);
    }

    public function handleDelivered(ShipmentDeliveredEvent $event): void
    {
        $this->action->handleDelivered($event);
    }

    public function handleFailed(ShipmentDeliveryFailedEvent $event): void
    {
        $this->action->handleFailed($event);
    }
}
