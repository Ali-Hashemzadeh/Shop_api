<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Domain\DTOs\OrderDTO;

interface OrderManagerInterface
{
    public function createOrderFromCart(int $userId, int $addressId, int $shipmentMethodId, ?string $notes = null): OrderDTO;

    public function markAsPaid(int $orderId, string $transactionRef): OrderDTO;

    public function markAsComplete(int $orderId): OrderDTO;

    public function getUserOrders(int $userId, int $perPage = 15): LengthAwarePaginator;

    public function findOrder(int $orderId): ?OrderDTO;
}
