<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Persistence\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\DTOs\InventoryStockDTO;
use Modules\Inventory\Domain\Events\InventoryRestockedEvent;
use Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\Domain\Exceptions\StockNotFoundException;
use Modules\Inventory\Domain\Models\InventoryLedgerEntry;
use Modules\Inventory\Domain\Models\InventoryStock;

class EloquentInventoryManager implements InventoryManagerInterface
{
    public function getStockBySku(string $sku): InventoryStockDTO
    {
        $stock = InventoryStock::where('sku', $sku)->first();

        if ($stock === null) {
            throw new StockNotFoundException($sku);
        }

        return InventoryStockDTO::fromModel($stock);
    }

    public function getBatchStockBySkus(array $skus): array
    {
        return InventoryStock::whereIn('sku', $skus)
            ->get()
            ->keyBy('sku')
            ->map(fn (InventoryStock $stock) => InventoryStockDTO::fromModel($stock))
            ->all();
    }

    public function adjustStock(
        string $sku,
        int $quantityChange,
        string $type,
        ?string $refType = null,
        ?int $refId = null,
        ?string $notes = null,
    ): InventoryStockDTO {
        return DB::transaction(function () use ($sku, $quantityChange, $type, $refType, $refId, $notes): InventoryStockDTO {
            $stock = InventoryStock::where('sku', $sku)->lockForUpdate()->first();

            if ($stock === null) {
                $stock = new InventoryStock(['sku' => $sku, 'quantity' => 0, 'reserved_quantity' => 0]);
            }

            $availableBefore = $stock->quantity - $stock->reserved_quantity;

            $stock->quantity += $quantityChange;
            $stock->save();

            InventoryLedgerEntry::create([
                'sku' => $sku,
                'type' => $type,
                'quantity_change' => $quantityChange,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'notes' => $notes,
            ]);

            $this->announceRestockIfTransitioned($sku, $availableBefore, $stock->quantity - $stock->reserved_quantity);

            return InventoryStockDTO::fromModel($stock);
        });
    }

    public function reserveStock(string $sku, int $quantity, int $orderId): bool
    {
        return DB::transaction(function () use ($sku, $quantity, $orderId): bool {
            $stock = InventoryStock::where('sku', $sku)->lockForUpdate()->first();

            if ($stock === null) {
                throw new StockNotFoundException($sku);
            }

            $available = $stock->quantity - $stock->reserved_quantity;

            if ($available < $quantity) {
                throw new InsufficientStockException($sku, $quantity, $available);
            }

            $stock->reserved_quantity += $quantity;
            $stock->save();

            InventoryLedgerEntry::create([
                'sku' => $sku,
                'type' => 'allocation',
                'quantity_change' => -$quantity,
                'reference_type' => 'order',
                'reference_id' => $orderId,
            ]);

            return true;
        });
    }

    public function commitReservation(string $sku, int $quantity, int $orderId): bool
    {
        return DB::transaction(function () use ($sku, $quantity, $orderId): bool {
            $stock = InventoryStock::where('sku', $sku)->lockForUpdate()->first();

            if ($stock === null) {
                throw new StockNotFoundException($sku);
            }

            $stock->quantity -= $quantity;
            $stock->reserved_quantity -= $quantity;
            $stock->save();

            InventoryLedgerEntry::create([
                'sku' => $sku,
                'type' => 'sale',
                'quantity_change' => -$quantity,
                'reference_type' => 'order',
                'reference_id' => $orderId,
            ]);

            return true;
        });
    }

    public function releaseReservation(string $sku, int $quantity, int $orderId): bool
    {
        return DB::transaction(function () use ($sku, $quantity, $orderId): bool {
            $stock = InventoryStock::where('sku', $sku)->lockForUpdate()->first();

            if ($stock === null) {
                throw new StockNotFoundException($sku);
            }

            $availableBefore = $stock->quantity - $stock->reserved_quantity;

            $stock->reserved_quantity -= $quantity;
            $stock->save();

            InventoryLedgerEntry::create([
                'sku' => $sku,
                'type' => 'release',
                'quantity_change' => $quantity,
                'reference_type' => 'order',
                'reference_id' => $orderId,
            ]);

            // Releasing a reservation raises available stock too, so a SKU that
            // was fully reserved (available <= 0) can cross back into
            // availability here — the same customer-visible transition as a
            // physical restock.
            $this->announceRestockIfTransitioned($sku, $availableBefore, $stock->quantity - $stock->reserved_quantity);

            return true;
        });
    }

    /**
     * Fire the restock event only on the exact transition from unavailable to
     * available: previous available <= 0 AND new available > 0. Every other
     * movement (a further top-up, a partial sell-down, a no-op) stays silent.
     *
     * Dispatched inside the caller's transaction; the after-commit listener
     * guarantees a rolled-back mutation never notifies anyone.
     */
    private function announceRestockIfTransitioned(string $sku, int $availableBefore, int $availableAfter): void
    {
        if ($availableBefore <= 0 && $availableAfter > 0) {
            Event::dispatch(new InventoryRestockedEvent(
                sku: $sku,
                previousAvailableQuantity: $availableBefore,
                newAvailableQuantity: $availableAfter,
            ));
        }
    }
}
