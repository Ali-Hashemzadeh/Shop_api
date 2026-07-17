<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Modules\Shipment\Application\Services\DeliverySlotGenerator;

class GenerateDeliverySlotsAction
{
    public function __construct(private readonly DeliverySlotGenerator $generator) {}

    public function handle(?int $days = null): int
    {
        $days ??= (int) config('shipment.delivery.generation_days', 30);

        return $this->generator->generate($days);
    }
}
