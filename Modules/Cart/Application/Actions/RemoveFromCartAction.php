<?php

declare(strict_types=1);

namespace Modules\Cart\Application\Actions;

use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Domain\DTOs\CartDTO;
use Modules\Cart\Domain\Exceptions\CartItemNotFoundException;

class RemoveFromCartAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
    ) {}

    /**
     * @throws CartItemNotFoundException
     */
    public function handle(int $cartId, int $itemId): CartDTO
    {
        return $this->cart->removeItem($cartId, $itemId);
    }
}
