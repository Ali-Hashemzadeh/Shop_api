<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Domain\DTOs\AdminOrderDetailDTO;
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

    /**
     * Admin/operator order listing. Paginator items are OrderDTOs (newest first).
     * Supported filter keys: status, order_id, user_id, date_from, date_to.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAdminOrders(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Admin/operator order detail: the order aggregate plus its current fulfillment
     * state resolved through the Shipment contract. Null when the order is missing.
     */
    public function getAdminOrderDetail(int $orderId): ?AdminOrderDetailDTO;
}
