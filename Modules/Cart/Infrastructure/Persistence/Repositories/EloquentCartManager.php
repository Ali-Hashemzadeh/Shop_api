<?php

declare(strict_types=1);

namespace Modules\Cart\Infrastructure\Persistence\Repositories;

use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Domain\DTOs\CartDTO;
use Modules\Cart\Domain\DTOs\CartItemDTO;
use Modules\Cart\Domain\Exceptions\CartItemNotFoundException;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;

class EloquentCartManager implements CartManagerInterface
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly InventoryManagerInterface $inventory,
    ) {}

    public function findOrCreateCart(?int $userId, ?string $sessionId): CartDTO
    {
        if ($userId !== null) {
            $cart = Cart::firstOrCreate(['user_id' => $userId]);
        } else {
            $cart = Cart::firstOrCreate(['session_id' => $sessionId]);
        }

        $cart->load('items');

        return $this->buildDTO($cart);
    }

    public function getCart(int $cartId): CartDTO
    {
        $cart = Cart::with('items')->findOrFail($cartId);

        return $this->buildDTO($cart);
    }

    public function addItem(int $cartId, string $sku, int $quantity): CartDTO
    {
        $cart = Cart::with('items')->findOrFail($cartId);

        $existing = $cart->items->firstWhere('sku', $sku);

        if ($existing !== null) {
            $existing->quantity += $quantity;
            $existing->save();
        } else {
            $cart->items()->create(['sku' => $sku, 'quantity' => $quantity]);
        }

        $cart->load('items');

        return $this->buildDTO($cart);
    }

    public function removeItem(int $cartId, int $itemId): CartDTO
    {
        $cart = Cart::with('items')->findOrFail($cartId);

        $item = $cart->items->find($itemId);

        if ($item === null) {
            throw new CartItemNotFoundException($itemId);
        }

        $item->delete();
        $cart->load('items');

        return $this->buildDTO($cart);
    }

    public function updateQuantity(int $cartId, int $itemId, int $quantity): CartDTO
    {
        $cart = Cart::with('items')->findOrFail($cartId);

        $item = $cart->items->find($itemId);

        if ($item === null) {
            throw new CartItemNotFoundException($itemId);
        }

        $item->quantity = $quantity;
        $item->save();

        $cart->load('items');

        return $this->buildDTO($cart);
    }

    public function clearCart(int $cartId): void
    {
        $cart = Cart::findOrFail($cartId);
        $cart->items()->delete();
    }

    private function buildDTO(Cart $cart): CartDTO
    {
        $items = $cart->items->map(function (CartItem $item): CartItemDTO {
            $variant = $this->catalog->findVariantBySku($item->sku);

            return CartItemDTO::fromModel(
                $item,
                productName: null,
                basePrice: $variant?->basePrice,
                compareAtPrice: $variant?->compareAtPrice,
                imageUrl: $variant?->imageUrl,
            );
        })->all();

        return CartDTO::fromModel($cart, $items);
    }
}
