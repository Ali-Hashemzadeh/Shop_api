<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\LikeProductAction;
use Modules\Catalog\Application\Actions\UnlikeProductAction;

/**
 * Like / unlike a product for the authenticated customer.
 *
 * Self-service, so there is no permission check — only authentication (the
 * route sits behind auth:sanctum; a guest gets 401). The user is always the
 * authenticated caller: a user_id is never accepted from the client, and a
 * customer can only ever touch their own like.
 */
class ProductLikeController extends Controller
{
    public function __construct(
        private readonly LikeProductAction $likeAction,
        private readonly UnlikeProductAction $unlikeAction,
    ) {}

    public function store(Request $request, string $uuid): JsonResponse
    {
        $this->likeAction->handle((int) $request->user()->id, $uuid);

        return response()->json(['liked' => true]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->unlikeAction->handle((int) $request->user()->id, $uuid);

        return response()->json(['liked' => false]);
    }
}
