<?php

declare(strict_types=1);

namespace Modules\Cart\Application\Actions;

use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Domain\DTOs\CartDTO;
use Modules\Cart\Domain\DTOs\CartItemDTO;
use Modules\Cart\Domain\Exceptions\CartItemNotFoundException;
use Modules\Cart\Domain\Exceptions\CartQuantityLimitExceededException;
use Modules\Cart\Domain\Exceptions\InsufficientStockException;
use Modules\Cart\Domain\Exceptions\ProductSkuNotFoundException;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\Exceptions\StockNotFoundException;

class UpdateCartItemAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
        private readonly InventoryManagerInterface $inventory,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    /**
     * @throws CartItemNotFoundException
     * @throws ProductSkuNotFoundException
     * @throws InsufficientStockException
     */
    public function handle(int $cartId, int $itemId, int $quantity): CartDTO
    {
        $cartDto = $this->cart->getCart($cartId);

        $item = collect($cartDto->items)->first(static fn (CartItemDTO $i) => $i->id === $itemId);

        if ($item === null) {
            throw new CartItemNotFoundException($itemId);
        }

        $variant = $this->catalog->findVariantBySku($item->sku);

        try {
            $stock = $this->inventory->getStockBySku($item->sku);
        } catch (StockNotFoundException) {
            throw new ProductSkuNotFoundException($item->sku);
        }

        if ($stock->availableQuantity < $quantity) {
            throw new InsufficientStockException($item->sku, $quantity, $stock->availableQuantity);
        }

        if ($variant?->maxQuantityPerOrder !== null && $quantity > $variant->maxQuantityPerOrder) {
            throw new CartQuantityLimitExceededException($item->sku, $variant->maxQuantityPerOrder, $quantity);
        }

        return $this->cart->updateQuantity($cartId, $itemId, $quantity);
    }
}
