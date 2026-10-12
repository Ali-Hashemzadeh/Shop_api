<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Blog\Domain\DTOs\BlogTagDTO;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogListRequest;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogPostsRequest;
use Modules\Blog\Infrastructure\Http\Resources\BlogAuthorResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogCategoryResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogPostSummaryResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogTagResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogLookupQueries;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostQueries;

/**
 * Public discovery pages: categories (tree), tags, authors — each with a
 * detail and a paginated "posts in X" listing. Inactive entities are 404 and
 * their archive pages list published posts only.
 */
class BlogTaxonomyController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogLookupQueries $lookups,
        private readonly BlogPostQueries $posts,
    ) {}

    // ── categories ──────────────────────────────────────────────────────────

    public function categories(): JsonResponse
    {
        return response()->json(['data' => BlogCategoryResource::collection($this->lookups->categoryTree(activeOnly: true))]);
    }

    public function category(string $slug): JsonResponse
    {
        $category = BlogCategory::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return response()->json(['data' => new BlogCategoryResource($this->lookups->categoryDto($category, withChildren: true))]);
    }

    /** Posts in this category and its active sub-categories. */
    public function categoryPosts(IndexPublicBlogPostsRequest $request, string $slug): JsonResponse
    {
        $category = BlogCategory::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return $this->posts([...$request->filters(), 'category' => null, 'category_id' => (int) $category->id], $request);
    }

    // ── tags ────────────────────────────────────────────────────────────────

    public function tags(IndexPublicBlogListRequest $request): JsonResponse
    {
        return $this->paginated(BlogTagResource::class, $this->lookups->paginateTags(
            ['search' => $request->validated('search')],
            (int) $request->validated('per_page', 15),
            activeOnly: true,
        ));
    }

    public function tag(string $slug): JsonResponse
    {
        $tag = BlogTag::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return response()->json(['data' => new BlogTagResource(BlogTagDTO::fromModel($tag))]);
    }

    public function tagPosts(IndexPublicBlogPostsRequest $request, string $slug): JsonResponse
    {
        $tag = BlogTag::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return $this->posts([...$request->filters(), 'tag' => $tag->slug], $request);
    }

    // ── authors ─────────────────────────────────────────────────────────────

    public function authors(IndexPublicBlogListRequest $request): JsonResponse
    {
        return $this->paginated(BlogAuthorResource::class, $this->lookups->paginateAuthors(
            ['search' => $request->validated('search')],
            (int) $request->validated('per_page', 15),
            activeOnly: true,
        ));
    }

    public function author(string $slug): JsonResponse
    {
        $author = BlogAuthor::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return response()->json(['data' => new BlogAuthorResource($this->lookups->authorDto($author))]);
    }

    public function authorPosts(IndexPublicBlogPostsRequest $request, string $slug): JsonResponse
    {
        $author = BlogAuthor::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return $this->posts([...$request->filters(), 'author' => null, 'author_id' => (int) $author->id], $request);
    }

    private function posts(array $filters, IndexPublicBlogPostsRequest $request): JsonResponse
    {
        return $this->paginated(BlogPostSummaryResource::class, $this->posts->paginatePublic(
            $filters,
            (int) $request->validated('per_page', 15),
            $this->viewerId($request),
        ));
    }
}
