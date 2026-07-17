<?php

declare(strict_types=1);

namespace Modules\Cart\Infrastructure\Persistence\Repositories;

use Illuminate\Support\Facades\DB;
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

    public function clearUserCart(int $userId): void
    {
        $cart = Cart::query()->where('user_id', $userId)->first();

        $cart?->items()->delete();
    }

    public function mergeGuestCart(int $userId, string $sessionId): CartDTO
    {
        return DB::transaction(function () use ($userId, $sessionId) {
            $guestCart = Cart::with('items')->where('session_id', $sessionId)->first();
            $userCart = Cart::firstOrCreate(['user_id' => $userId]);

            if ($guestCart === null || $guestCart->items->isEmpty()) {
                $userCart->load('items');

                return $this->buildDTO($userCart);
            }

            $userCart->load('items');
            $skus = $guestCart->items->pluck('sku')->unique()->values()->all();
            $variants = $this->catalog->getVariantsBySkus($skus);
            $stocks = $this->inventory->getBatchStockBySkus($skus);

            foreach ($guestCart->items as $guestItem) {
                $variant = $variants[$guestItem->sku] ?? null;
                $stock = $stocks[$guestItem->sku] ?? null;

                if ($stock === null || $stock->availableQuantity <= 0) {
                    continue;
                }

                $existing = $userCart->items->firstWhere('sku', $guestItem->sku);
                $requestedQuantity = ($existing?->quantity ?? 0) + $guestItem->quantity;
                $allowedQuantity = min(
                    $requestedQuantity,
                    $stock->availableQuantity,
                    $variant?->maxQuantityPerOrder ?? PHP_INT_MAX,
                );

                if ($allowedQuantity <= 0) {
                    continue;
                }

                if ($existing !== null) {
                    if ($existing->quantity !== $allowedQuantity) {
                        $existing->update(['quantity' => $allowedQuantity]);
                    }
                } else {
                    $userCart->items()->create([
                        'sku' => $guestItem->sku,
                        'quantity' => $allowedQuantity,
                    ]);
                }
            }

            $guestCart->delete();
            $userCart->load('items');

            return $this->buildDTO($userCart);
        });
    }

    private function buildDTO(Cart $cart): CartDTO
    {
        $skus = $cart->items->pluck('sku')->all();
        $variants = $this->catalog->getVariantsBySkus($skus);
        $stocks = $this->inventory->getBatchStockBySkus($skus);

        $items = $cart->items->map(function (CartItem $item) use ($variants, $stocks): CartItemDTO {
            $variant = $variants[$item->sku] ?? null;

            return CartItemDTO::fromModel(
                $item,
                productName: $variant?->productName,
                basePrice: $variant?->basePrice,
                compareAtPrice: $variant?->compareAtPrice,
                imageUrl: $variant?->imageUrl,
                attributes: $variant?->attributes ?? [],
                availableStock: $stocks[$item->sku]->availableQuantity ?? 0,
                maxQuantityPerOrder: $variant?->maxQuantityPerOrder,
            );
        })->all();

        return CartDTO::fromModel($cart, $items);
    }
}
