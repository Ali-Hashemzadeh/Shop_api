<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\Contracts;

use Modules\Cart\Domain\DTOs\CartDTO;
use Modules\Cart\Domain\Exceptions\CartItemNotFoundException;
use Modules\Cart\Domain\Exceptions\InsufficientStockException;
use Modules\Cart\Domain\Exceptions\ProductSkuNotFoundException;

interface CartManagerInterface
{
    /** Find or create a cart for the given user or guest session. */
    public function findOrCreateCart(?int $userId, ?string $sessionId): CartDTO;

    /** Return the cart with items enriched by current Catalog pricing. */
    public function getCart(int $cartId): CartDTO;

    /**
     * Add a quantity of a SKU to the cart. If the SKU already exists, increments quantity.
     *
     * @throws ProductSkuNotFoundException
     * @throws InsufficientStockException
     */
    public function addItem(int $cartId, string $sku, int $quantity): CartDTO;

    /**
     * Remove a specific item line from the cart.
     *
     * @throws CartItemNotFoundException
     */
    public function removeItem(int $cartId, int $itemId): CartDTO;

    /**
     * Update the quantity of an existing cart item.
     *
     * @throws CartItemNotFoundException
     */
    public function updateQuantity(int $cartId, int $itemId, int $quantity): CartDTO;

    /** Remove all items from the cart. */
    public function clearCart(int $cartId): void;

    /** Remove all items from an authenticated user's cart, if it exists. */
    public function clearUserCart(int $userId): void;

    /**
     * Merge a guest session cart into an authenticated user's cart.
     * Quantities are clamped to available stock; SKUs with no inventory record are skipped.
     * The guest cart is deleted after merging.
     */
    public function mergeGuestCart(int $userId, string $sessionId): CartDTO;
}
