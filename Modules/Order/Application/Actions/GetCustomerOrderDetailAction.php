<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\DTOs\CustomerOrderDetailDTO;
use Modules\Payment\Domain\Contracts\PaymentManagerInterface;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;

class GetCustomerOrderDetailAction
{
    public function __construct(
        private readonly OrderManagerInterface $orders,
        private readonly PaymentManagerInterface $payments,
        private readonly ShipmentManagerInterface $shipments,
    ) {}

    public function handle(int $userId, string $publicCode): ?CustomerOrderDetailDTO
    {
        $order = $this->orders->findUserOrderByPublicCode($userId, $publicCode);

        if ($order === null) {
            return null;
        }

        return new CustomerOrderDetailDTO(
            order: $order,
            payments: $this->payments->getForOrder($order->id),
            shipment: $this->shipments->findForOrder($order->id),
        );
    }
}
