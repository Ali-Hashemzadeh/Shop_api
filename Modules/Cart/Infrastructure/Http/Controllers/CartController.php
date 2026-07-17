<?php

declare(strict_types=1);

namespace Modules\Cart\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cart\Application\Actions\AddToCartAction;
use Modules\Cart\Application\Actions\ClearCartAction;
use Modules\Cart\Application\Actions\GetCartAction;
use Modules\Cart\Application\Actions\MergeCartAction;
use Modules\Cart\Application\Actions\RemoveFromCartAction;
use Modules\Cart\Application\Actions\UpdateCartItemAction;
use Modules\Cart\Domain\Exceptions\CartItemNotFoundException;
use Modules\Cart\Domain\Exceptions\CartQuantityLimitExceededException;
use Modules\Cart\Domain\Exceptions\InsufficientStockException;
use Modules\Cart\Domain\Exceptions\ProductSkuNotFoundException;
use Modules\Cart\Infrastructure\Http\Requests\AddCartItemRequest;
use Modules\Cart\Infrastructure\Http\Requests\MergeCartRequest;
use Modules\Cart\Infrastructure\Http\Requests\UpdateCartItemRequest;
use Modules\Cart\Infrastructure\Http\Resources\CartResource;

class CartController extends Controller
{
    public function __construct(
        private readonly GetCartAction $getCart,
        private readonly AddToCartAction $addToCart,
        private readonly UpdateCartItemAction $updateItem,
        private readonly RemoveFromCartAction $removeItem,
        private readonly ClearCartAction $clearCart,
        private readonly MergeCartAction $mergeCart,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $dto = $this->getCart->handle((int) $request->attributes->get('cart_id'));

        return response()->json(new CartResource($dto));
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        try {
            $dto = $this->addToCart->handle(
                (int) $request->attributes->get('cart_id'),
                $request->validated('sku'),
                $request->validated('quantity'),
            );
        } catch (ProductSkuNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CartQuantityLimitExceededException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['quantity' => [$e->validationMessage()]],
            ], 422);
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new CartResource($dto), 201);
    }

    public function updateItem(UpdateCartItemRequest $request, int $itemId): JsonResponse
    {
        try {
            $dto = $this->updateItem->handle(
                (int) $request->attributes->get('cart_id'),
                $itemId,
                $request->validated('quantity'),
            );
        } catch (CartItemNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (ProductSkuNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CartQuantityLimitExceededException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['quantity' => [$e->validationMessage()]],
            ], 422);
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new CartResource($dto));
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        try {
            $dto = $this->removeItem->handle(
                (int) $request->attributes->get('cart_id'),
                $itemId,
            );
        } catch (CartItemNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(new CartResource($dto));
    }

    public function clear(Request $request): JsonResponse
    {
        $this->clearCart->handle((int) $request->attributes->get('cart_id'));

        return response()->json(null, 204);
    }

    public function merge(MergeCartRequest $request): JsonResponse
    {
        $dto = $this->mergeCart->handle(
            $request->user()->id,
            $request->validated('session_id'),
        );

        return response()->json(new CartResource($dto));
    }
}
