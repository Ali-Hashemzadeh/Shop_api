<?php

declare(strict_types=1);

namespace Modules\Cart\Application\Actions;

use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Domain\DTOs\CartDTO;
use Modules\Cart\Domain\Exceptions\InsufficientStockException;
use Modules\Cart\Domain\Exceptions\ProductSkuNotFoundException;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\Exceptions\StockNotFoundException;

class AddToCartAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
        private readonly InventoryManagerInterface $inventory,
    ) {}

    /**
     * @throws ProductSkuNotFoundException
     * @throws InsufficientStockException
     */
    public function handle(int $cartId, string $sku, int $quantity): CartDTO
    {
        try {
            $stock = $this->inventory->getStockBySku($sku);
        } catch (StockNotFoundException) {
            throw new ProductSkuNotFoundException($sku);
        }

        if ($stock->availableQuantity < $quantity) {
            throw new InsufficientStockException($sku, $quantity, $stock->availableQuantity);
        }

        return $this->cart->addItem($cartId, $sku, $quantity);
    }
}
