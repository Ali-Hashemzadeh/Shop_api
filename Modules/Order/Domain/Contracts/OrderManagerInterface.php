<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Order\Domain\DTOs\AdminOrderDetailDTO;
use Modules\Order\Domain\DTOs\OrderDTO;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;

interface OrderManagerInterface
{
    public function createOrderFromCart(int $userId, ShipmentSelectionDTO $selection, ?string $notes = null): OrderDTO;

    /**
     * Freeze the order's payable pricing before a payment attempt is created.
     *
     * Order — not Payment — owns this, because it is the module that knows the
     * merchandise subtotal, shipping, and tax, and it is the order that must charge
     * one identical amount across every payment attempt.
     *
     * The FIRST call locks the coupon decision, including the decision to use none,
     * and stamps payment_pricing_finalized_at. Afterwards the coupon, its amount,
     * its snapshot, and total_amount are immutable: a later call may repeat the same
     * code or omit it entirely, but supplying a *different* code is rejected. A
     * failed gateway call deliberately leaves the freeze in place so a retry charges
     * the same figure.
     *
     * @param  string|null  $couponCode  Raw customer input; normalization happens inside.
     *
     * @throws ValidationException 422 when the coupon is
     *                             unusable, would change frozen pricing, or would leave a non-positive total
     */
    public function finalizeForPayment(int $orderId, int $userId, ?string $couponCode = null): OrderDTO;

    public function markAsPaid(int $orderId, string $transactionRef): OrderDTO;

    public function markAsComplete(int $orderId): OrderDTO;

    /**
     * Set the order's summary status from a shipment transition (paid / processing
     * / shipped / completed). Called by the Shipment module across the contract.
     */
    public function syncStatusFromShipment(int $orderId, string $orderStatus): void;

    /**
     * The caller's own orders, newest first.
     *
     * $search is an optional exact `bdo-XXXXXX` public code (case-insensitive).
     * Ownership is applied unconditionally and is never widened by the search, so
     * another customer's code returns an empty page rather than their order — and
     * is indistinguishable from a code that does not exist.
     */
    public function getUserOrders(int $userId, int $perPage = 15, ?string $search = null): LengthAwarePaginator;

    public function findOrder(int $orderId): ?OrderDTO;

    /**
     * Find one order by its customer-facing code, scoped in the query to the
     * authenticated owner. Missing and foreign-owned codes both return null.
     */
    public function findUserOrderByPublicCode(int $userId, string $publicCode): ?OrderDTO;

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

    /**
     * True when the user has at least one order item for this product on an
     * order in a realized state (the same status set `sales_count` uses).
     *
     * Consumed by the Review module for verified-purchase gating; Order answers
     * the question from its own tables — no cross-module query ever happens.
     */
    public function hasPurchasedProduct(int $userId, int $productId): bool;
}
