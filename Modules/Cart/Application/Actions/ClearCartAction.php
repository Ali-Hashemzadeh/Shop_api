<?php

declare(strict_types=1);

namespace Modules\Cart\Application\Actions;

use Modules\Cart\Domain\Contracts\CartManagerInterface;

class ClearCartAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
    ) {}

    public function handle(int $cartId): void
    {
        $this->cart->clearCart($cartId);
    }
}
