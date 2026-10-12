<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostRevision;
use Modules\Blog\Infrastructure\Http\Resources\BlogPostDetailResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostHydrator;

/**
 * Signed preview of ONE unpublished post (or one of its revisions). Reachable
 * only through a URL minted by POST /admin/blog/posts/{id}/preview-link — the
 * `signed` middleware rejects a missing, tampered, or expired signature with
 * 403 before this runs. The response is marked `is_preview` and tells the
 * frontend the real status; it is never cached as public content.
 */
class BlogPreviewController extends Controller
{
    public function __construct(
        private readonly BlogPostHydrator $hydrator,
    ) {}

    public function __invoke(Request $request, int $post): JsonResponse
    {
        $model = BlogPost::query()->findOrFail($post);

        if ($request->filled('revision')) {
            $revision = BlogPostRevision::query()
                ->where('blog_post_id', $model->id)
                ->findOrFail((int) $request->query('revision'));

            // An in-memory overlay — never saved.
            $model->title = $revision->title;
            $model->slug = $revision->slug;
            $model->excerpt = $revision->excerpt;
            $model->content = $revision->content;
            $model->featured_media_id = $revision->featured_media_id;
        }

        return response()
            ->json(['data' => new BlogPostDetailResource($this->hydrator->detail($model, null, null, isPreview: true))])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
