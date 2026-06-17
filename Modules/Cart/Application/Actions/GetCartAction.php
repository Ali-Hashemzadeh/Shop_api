<?php

declare(strict_types=1);

namespace Modules\Cart\Application\Actions;

use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Domain\DTOs\CartDTO;

class GetCartAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
    ) {}

    public function handle(int $cartId): CartDTO
    {
        return $this->cart->getCart($cartId);
    }
}
