<?php

declare(strict_types=1);

namespace Modules\Cart\Application\Actions;

use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Domain\DTOs\CartDTO;
use Modules\Cart\Domain\Exceptions\CartQuantityLimitExceededException;
use Modules\Cart\Domain\Exceptions\InsufficientStockException;
use Modules\Cart\Domain\Exceptions\ProductSkuNotFoundException;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\Exceptions\StockNotFoundException;

class AddToCartAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
        private readonly InventoryManagerInterface $inventory,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    /**
     * @throws ProductSkuNotFoundException
     * @throws InsufficientStockException
     */
    public function handle(int $cartId, string $sku, int $quantity): CartDTO
    {
        $variant = $this->catalog->findVariantBySku($sku);

        $cart = $this->cart->getCart($cartId);
        $existingQuantity = collect($cart->items)->firstWhere('sku', $sku)?->quantity ?? 0;
        $resultingQuantity = $existingQuantity + $quantity;

        try {
            $stock = $this->inventory->getStockBySku($sku);
        } catch (StockNotFoundException) {
            throw new ProductSkuNotFoundException($sku);
        }

        if ($stock->availableQuantity < $resultingQuantity) {
            throw new InsufficientStockException($sku, $resultingQuantity, $stock->availableQuantity);
        }

        if ($variant?->maxQuantityPerOrder !== null && $resultingQuantity > $variant->maxQuantityPerOrder) {
            throw new CartQuantityLimitExceededException($sku, $variant->maxQuantityPerOrder, $resultingQuantity);
        }

        return $this->cart->addItem($cartId, $sku, $quantity);
    }
}
