<?php

declare(strict_types=1);

namespace Modules\Cart\Application\Actions;

use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Domain\DTOs\CartDTO;

class MergeCartAction
{
    public function __construct(
        private readonly CartManagerInterface $cart,
    ) {}

    public function handle(int $userId, string $sessionId): CartDTO
    {
        return $this->cart->mergeGuestCart($userId, $sessionId);
    }
}
