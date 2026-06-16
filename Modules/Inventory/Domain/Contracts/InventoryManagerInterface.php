<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Contracts;

use Modules\Inventory\Domain\DTOs\InventoryStockDTO;
use Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\Domain\Exceptions\StockNotFoundException;

interface InventoryManagerInterface
{
    /**
     * Fetch stock for a single SKU.
     *
     * @throws StockNotFoundException
     */
    public function getStockBySku(string $sku): InventoryStockDTO;

    /**
     * Batch-fetch stock for multiple SKUs.
     * Returns array<string, InventoryStockDTO> keyed by SKU.
     * Unknown SKUs are silently absent from the result.
     */
    public function getBatchStockBySkus(array $skus): array;

    /**
     * Apply a signed quantity change (positive = add, negative = deduct)
     * and append a ledger entry. Creates the stock record on first call.
     */
    public function adjustStock(
        string $sku,
        int $quantityChange,
        string $type,
        ?string $refType = null,
        ?int $refId = null,
        ?string $notes = null,
    ): InventoryStockDTO;

    /**
     * Increase reserved_quantity, blocking oversell.
     *
     * @throws InsufficientStockException
     */
    public function reserveStock(string $sku, int $quantity, int $orderId): bool;

    /**
     * Convert a reservation into a permanent deduction (order fulfilled).
     */
    public function commitReservation(string $sku, int $quantity, int $orderId): bool;

    /**
     * Return reserved units back to the available pool (order cancelled).
     */
    public function releaseReservation(string $sku, int $quantity, int $orderId): bool;
}
