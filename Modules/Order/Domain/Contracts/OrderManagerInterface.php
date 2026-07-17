<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;

interface OrderManagerInterface
{
    public function createOrderFromCart(int $userId, ShipmentSelectionDTO $selection, ?string $notes = null): OrderDTO;

    public function markAsPaid(int $orderId, string $transactionRef): OrderDTO;

    public function markAsComplete(int $orderId): OrderDTO;

    /**
     * Set the order's summary status from a shipment transition (paid / processing
     * / shipped / completed). Called by the Shipment module across the contract.
     */
    public function syncStatusFromShipment(int $orderId, string $orderStatus): void;

    public function getUserOrders(int $userId, int $perPage = 15): LengthAwarePaginator;

    public function findOrder(int $orderId): ?OrderDTO;
}
