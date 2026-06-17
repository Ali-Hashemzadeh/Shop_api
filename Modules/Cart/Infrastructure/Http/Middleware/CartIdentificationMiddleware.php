<?php

declare(strict_types=1);

namespace Modules\Cart\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class CartIdentificationMiddleware
{
    public function __construct(
        private readonly CartManagerInterface $cart,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user('sanctum')?->id;
        $sessionId = $userId === null ? ($request->header('X-Session-Id') ?? $this->generateSessionId()) : null;

        $cartDto = $this->cart->findOrCreateCart($userId, $sessionId);

        $request->attributes->set('cart_id', $cartDto->id);
        $request->attributes->set('cart_session_id', $cartDto->sessionId);

        $response = $next($request);

        if ($userId === null && $cartDto->sessionId !== null) {
            $response->headers->set('X-Cart-Session-Id', $cartDto->sessionId);
        }

        return $response;
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
