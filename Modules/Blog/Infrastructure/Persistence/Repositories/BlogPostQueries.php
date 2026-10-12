<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Persistence\Repositories;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Enums\BlogPostSort;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostSlugHistory;
use Modules\Blog\Domain\Models\BlogPostStat;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Blog\Domain\Services\PersianTextNormalizer;

/**
 * Read-side queries over Blog's own tables: public listing/search, admin
 * listing, slug + slug-history resolution, related posts, bookmarks.
 *
 * Every public query starts from {@see BlogPost::scopePubliclyVisible()}, so no
 * draft, in-review, approved, scheduled, archived, expired, or soft-deleted post
 * can leak through a filter combination. Hydration into DTOs is delegated to
 * {@see BlogPostHydrator} (page-wide batching, no N+1).
 */
final class BlogPostQueries
{
    public function __construct(
        private readonly BlogPostHydrator $hydrator,
        private readonly BlogCategoryTree $categoryTree,
    ) {}

    /**
     * Supported filters: search, category (slug), category_id, tag (slug),
     * author (slug), author_id, product_id, featured, published_from,
     * published_to, sort.
     */
    public function paginatePublic(array $filters, int $perPage, ?int $viewerId): LengthAwarePaginator
    {
        $query = BlogPost::query()->publiclyVisible()->select(BlogPost::listColumns())->with(BlogPostHydrator::SUMMARY_RELATIONS);

        $this->applyPublicFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        $paginator = $query->paginate($perPage)->withQueryString();

        return $paginator->setCollection(collect($this->hydrator->summaries($paginator->getCollection(), $viewerId)));
    }

    /**
     * Resolve a public post by slug, by an exact public code, or by a retired
     * slug from the slug history. Returns null when nothing publicly visible
     * matches — an unpublished post is indistinguishable from a missing one.
     *
     * @return array{post: BlogPost, redirected_from: ?string}|null
     */
    public function findPublic(string $slugOrCode): ?array
    {
        if (PublicCodeGenerator::matches($slugOrCode, PublicCodeEntity::BlogPost)) {
            $post = BlogPost::query()->publiclyVisible()
                ->where('public_code', PublicCodeGenerator::normalize($slugOrCode))
                ->first();

            return $post === null ? null : ['post' => $post, 'redirected_from' => null];
        }

        $post = BlogPost::query()->publiclyVisible()->where('slug', $slugOrCode)->first();
        if ($post !== null) {
            return ['post' => $post, 'redirected_from' => null];
        }

        // A retired slug resolves to the post that owned it, at its current slug.
        $history = BlogPostSlugHistory::query()->where('slug', $slugOrCode)->first();
        if ($history === null) {
            return null;
        }

        $post = BlogPost::query()->publiclyVisible()->whereKey($history->blog_post_id)->first();

        // The current slug can never equal a historical one (history rows are
        // dropped when a post reclaims an old slug), so this cannot loop.
        return $post === null || $post->slug === $slugOrCode
            ? null
            : ['post' => $post, 'redirected_from' => $slugOrCode];
    }

    /**
     * Supported filters: search, status, category_id, tag_id, author_id,
     * featured, published_from, published_to, created_from, created_to,
     * trashed (with|only), sort (newest|oldest|updated|title).
     */
    public function paginateAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = BlogPost::query()->select(BlogPost::listColumns())->with(BlogPostHydrator::SUMMARY_RELATIONS);

        match ($filters['trashed'] ?? null) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };

        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);

            if (PublicCodeGenerator::matches($search, PublicCodeEntity::BlogPost)) {
                $query->where('public_code', PublicCodeGenerator::normalize($search));
            } else {
                $normalized = PersianTextNormalizer::normalize($search);
                $query->where(function (Builder $q) use ($search, $normalized): void {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('search_text', 'like', "%{$normalized}%");
                });
            }
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', (string) $filters['status']);
        }
        if (filled($filters['category_id'] ?? null)) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('blog_categories.id', (int) $filters['category_id']));
        }
        if (filled($filters['tag_id'] ?? null)) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('blog_tags.id', (int) $filters['tag_id']));
        }
        if (filled($filters['author_id'] ?? null)) {
            $query->whereHas('authors', fn (Builder $q) => $q->where('blog_authors.id', (int) $filters['author_id']));
        }
        if (array_key_exists('featured', $filters) && $filters['featured'] !== null) {
            $query->where('is_featured', (bool) $filters['featured']);
        }
        $this->applyDateRange($query, 'published_at', $filters['published_from'] ?? null, $filters['published_to'] ?? null);
        $this->applyDateRange($query, 'created_at', $filters['created_from'] ?? null, $filters['created_to'] ?? null);

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'updated' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            'title' => $query->orderBy('title')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage)->withQueryString();

        return $paginator->setCollection(collect($this->hydrator->adminList($paginator->getCollection())));
    }

    /**
     * Curated related posts first (published only, curated order), then — when
     * they do not fill `$limit` — a fallback ranked by shared categories + tags
     * and recency. Fixed query count: one for curated, one for the fallback.
     *
     * @return EloquentCollection<int, BlogPost>
     */
    public function related(BlogPost $post, int $limit): EloquentCollection
    {
        $curated = BlogPost::query()->publiclyVisible()
            ->join('blog_related_posts', 'blog_related_posts.related_blog_post_id', '=', 'blog_posts.id')
            ->where('blog_related_posts.blog_post_id', $post->id)
            ->where('blog_posts.id', '!=', $post->id)
            ->orderBy('blog_related_posts.sort_order')
            ->select(BlogPost::listColumns())
            ->limit($limit)
            ->get();

        $remaining = $limit - $curated->count();
        if ($remaining <= 0) {
            return $curated;
        }

        $categoryIds = DB::table('blog_post_category')->where('blog_post_id', $post->id)->pluck('blog_category_id')->all();
        $tagIds = DB::table('blog_post_tag')->where('blog_post_id', $post->id)->pluck('blog_tag_id')->all();

        if ($categoryIds === [] && $tagIds === []) {
            return $curated;
        }

        $exclude = [$post->id, ...$curated->pluck('id')->all()];

        $sharedCategories = DB::table('blog_post_category')
            ->selectRaw('count(*)')
            ->whereColumn('blog_post_category.blog_post_id', 'blog_posts.id')
            ->whereIn('blog_category_id', $categoryIds === [] ? [0] : $categoryIds);
        $sharedTags = DB::table('blog_post_tag')
            ->selectRaw('count(*)')
            ->whereColumn('blog_post_tag.blog_post_id', 'blog_posts.id')
            ->whereIn('blog_tag_id', $tagIds === [] ? [0] : $tagIds);

        $fallback = BlogPost::query()->publiclyVisible()
            ->whereNotIn('blog_posts.id', $exclude)
            ->where(function (Builder $q) use ($categoryIds, $tagIds): void {
                $q->whereHas('categories', fn (Builder $c) => $c->whereIn('blog_categories.id', $categoryIds === [] ? [0] : $categoryIds))
                    ->orWhereHas('tags', fn (Builder $t) => $t->whereIn('blog_tags.id', $tagIds === [] ? [0] : $tagIds));
            })
            ->select(BlogPost::listColumns())
            // One computed alias ordered by bare name: portable across SQLite,
            // MySQL and Postgres (Postgres rejects aliases inside expressions).
            ->selectRaw(
                '(('.$sharedCategories->toSql().') + ('.$sharedTags->toSql().')) as shared_score',
                [...$sharedCategories->getBindings(), ...$sharedTags->getBindings()],
            )
            ->orderByDesc('shared_score')
            ->orderByDesc('published_at')
            ->orderByDesc('blog_posts.id')
            ->limit($remaining)
            ->get();

        return $curated->concat($fallback)->values();
    }

    /** Paginated saved posts for one reader — only those still publicly visible. */
    public function paginateBookmarks(int $userId, int $perPage): LengthAwarePaginator
    {
        $paginator = BlogPost::query()->publiclyVisible()
            ->join('blog_post_bookmarks', 'blog_post_bookmarks.blog_post_id', '=', 'blog_posts.id')
            ->where('blog_post_bookmarks.user_id', $userId)
            ->orderByDesc('blog_post_bookmarks.created_at')
            ->orderByDesc('blog_posts.id')
            ->select(BlogPost::listColumns())
            ->with(BlogPostHydrator::SUMMARY_RELATIONS)
            ->paginate($perPage)
            ->withQueryString();

        return $paginator->setCollection(collect($this->hydrator->summaries($paginator->getCollection(), $userId)));
    }

    /**
     * Publicly visible posts among the given ids, in the given order.
     *
     * @param  list<int>  $ids
     * @return EloquentCollection<int, BlogPost>
     */
    public function publicPostsInOrder(array $ids): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        $posts = BlogPost::query()->publiclyVisible()->select(BlogPost::listColumns())->whereIn('blog_posts.id', $ids)->with(BlogPostHydrator::SUMMARY_RELATIONS)->get()->keyBy('id');

        return new EloquentCollection(array_values(array_filter(array_map(fn (int $id) => $posts->get($id), $ids))));
    }

    // ── filters ─────────────────────────────────────────────────────────────

    private function applyPublicFilters(Builder $query, array $filters): void
    {
        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);

            if (PublicCodeGenerator::matches($search, PublicCodeEntity::BlogPost)) {
                // Exact, whole-string, normalized — never a partial code match.
                $query->where('public_code', PublicCodeGenerator::normalize($search));
            } else {
                $normalized = PersianTextNormalizer::normalize($search);
                $query->where(function (Builder $q) use ($search, $normalized): void {
                    $q->where('search_text', 'like', "%{$normalized}%")
                        ->orWhereHas('categories', fn (Builder $c) => $c->where('blog_categories.is_active', true)->where('blog_categories.name', 'like', "%{$search}%"))
                        ->orWhereHas('tags', fn (Builder $t) => $t->where('blog_tags.is_active', true)->where('blog_tags.name', 'like', "%{$search}%"));
                });
            }
        }

        $categoryId = null;
        if (filled($filters['category'] ?? null)) {
            $categoryId = BlogCategory::query()->where('slug', (string) $filters['category'])->where('is_active', true)->value('id') ?? 0;
        } elseif (filled($filters['category_id'] ?? null)) {
            $categoryId = (int) $filters['category_id'];
        }
        if ($categoryId !== null) {
            $ids = $categoryId > 0 ? $this->categoryTree->descendantsOf((int) $categoryId, activeOnly: true) : [];
            $query->whereHas('categories', fn (Builder $q) => $q->whereIn('blog_categories.id', $ids === [] ? [0] : $ids));
        }

        if (filled($filters['tag'] ?? null)) {
            $tagId = BlogTag::query()->where('slug', (string) $filters['tag'])->where('is_active', true)->value('id') ?? 0;
            $query->whereHas('tags', fn (Builder $q) => $q->where('blog_tags.id', $tagId));
        }

        $authorId = null;
        if (filled($filters['author'] ?? null)) {
            $authorId = BlogAuthor::query()->where('slug', (string) $filters['author'])->where('is_active', true)->value('id') ?? 0;
        } elseif (filled($filters['author_id'] ?? null)) {
            $authorId = (int) $filters['author_id'];
        }
        if ($authorId !== null) {
            $query->whereHas('authors', fn (Builder $q) => $q->where('blog_authors.id', $authorId));
        }

        if (filled($filters['product_id'] ?? null)) {
            $query->whereIn('blog_posts.id', DB::table('blog_post_products')->select('blog_post_id')->where('product_id', (int) $filters['product_id']));
        }

        if (array_key_exists('featured', $filters) && $filters['featured'] !== null) {
            $query->where('blog_posts.is_featured', (bool) $filters['featured']);
        }

        $this->applyDateRange($query, 'blog_posts.published_at', $filters['published_from'] ?? null, $filters['published_to'] ?? null);
    }

    private function applyDateRange(Builder $query, string $column, mixed $from, mixed $to): void
    {
        if (filled($from)) {
            $query->where($column, '>=', Carbon::parse((string) $from)->startOfDay());
        }
        if (filled($to)) {
            $query->where($column, '<=', Carbon::parse((string) $to)->endOfDay());
        }
    }

    /**
     * Metric sorts read the maintained `blog_post_stats` counters via correlated
     * subqueries (Blog's own table), with recency as a deterministic tiebreak.
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        $stat = fn (string $expression, array $bindings = []) => BlogPostStat::query()
            ->selectRaw($expression, $bindings)
            ->whereColumn('blog_post_stats.blog_post_id', 'blog_posts.id');

        switch (BlogPostSort::tryFrom((string) $sort) ?? BlogPostSort::Newest) {
            case BlogPostSort::Oldest:
                $query->orderBy('blog_posts.published_at')->orderBy('blog_posts.id');

                return;
            case BlogPostSort::MostViewed:
                $query->orderByDesc($stat('views_count'));
                break;
            case BlogPostSort::MostCommented:
                $query->orderByDesc($stat('comments_count'));
                break;
            case BlogPostSort::Popular:
                $w = config('blog.popularity_weights');
                $query->orderByDesc($stat(
                    'views_count * ? + likes_count * ? + bookmarks_count * ? + comments_count * ?',
                    [(int) ($w['views'] ?? 1), (int) ($w['likes'] ?? 3), (int) ($w['bookmarks'] ?? 4), (int) ($w['comments'] ?? 5)],
                ));
                break;
            case BlogPostSort::Newest:
                break;
        }

        $query->orderByDesc('blog_posts.published_at')->orderByDesc('blog_posts.id');
    }
}
