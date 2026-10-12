<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostQueries;

/**
 * Shared helpers for Blog controllers.
 *
 * Public routes carry no auth middleware, yet a signed-in reader should see
 * `is_liked` / `is_bookmarked`. The optional `auth('sanctum')` lookup (Cart's
 * pattern) resolves the viewer when a token is present and never 401s a guest.
 */
trait InteractsWithBlogViewer
{
    protected function viewerId(Request $request): ?int
    {
        $user = $request->user() ?? auth('sanctum')->user();

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }

    /** A publicly visible post by slug / public code / retired slug, or 404. */
    protected function publicPostOrFail(string $slug): BlogPost
    {
        $found = app(BlogPostQueries::class)->findPublic($slug);

        abort_if($found === null, 404);

        return $found['post'];
    }

    /** The repository's paginated envelope: {data, links, meta}. */
    protected function paginated(string $resourceClass, LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json($resourceClass::collection($paginator)->response()->getData(true));
    }
}
