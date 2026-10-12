<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Persistence\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Blog\Domain\DTOs\BlogAuthorDTO;
use Modules\Blog\Domain\DTOs\BlogCategoryDTO;
use Modules\Blog\Domain\DTOs\BlogCollectionDTO;
use Modules\Blog\Domain\DTOs\BlogPlacementDTO;
use Modules\Blog\Domain\DTOs\BlogPostSummaryDTO;
use Modules\Blog\Domain\DTOs\BlogTagDTO;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogCollection;
use Modules\Blog\Domain\Models\BlogPlacement;
use Modules\Blog\Domain\Models\BlogTag;

/**
 * Reads for Blog's discovery entities (categories, tags, authors) and curation
 * (collections, placements), public and admin. Media is batch-resolved per page.
 */
final class BlogLookupQueries
{
    public function __construct(
        private readonly BlogPostHydrator $hydrator,
        private readonly BlogPostQueries $posts,
    ) {}

    // ── categories ──────────────────────────────────────────────────────────

    /**
     * The full category tree (roots → children), deterministic order
     * (sort_order, name, id). Public trees skip inactive categories and their
     * whole subtree.
     *
     * @return list<BlogCategoryDTO>
     */
    public function categoryTree(bool $activeOnly): array
    {
        $query = BlogCategory::query()->orderBy('sort_order')->orderBy('name')->orderBy('id');
        if ($activeOnly) {
            $query->where('is_active', true);
        }
        $all = $query->get();
        $mediaMap = $this->hydrator->mediaMap($all->pluck('media_id')->all());
        $byParent = $all->groupBy(fn (BlogCategory $c) => (int) ($c->parent_id ?? 0));

        $build = function (int $parentId) use (&$build, $byParent, $mediaMap): array {
            return ($byParent->get($parentId) ?? collect())
                ->map(fn (BlogCategory $c) => BlogCategoryDTO::fromModel(
                    $c,
                    $this->hydrator->mediaDto($mediaMap, $c->media_id),
                    null,
                    $build((int) $c->id),
                ))
                ->values()
                ->all();
        };

        return $build(0);
    }

    public function paginateCategoriesAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = BlogCategory::query();
        $this->applyNameFilters($query, $filters, 'name');
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');

        $paginator = $query->paginate($perPage)->withQueryString();
        $mediaMap = $this->hydrator->mediaMap($paginator->getCollection()->pluck('media_id')->all());

        return $paginator->setCollection($paginator->getCollection()->map(
            fn (BlogCategory $c) => BlogCategoryDTO::fromModel($c, $this->hydrator->mediaDto($mediaMap, $c->media_id))
        ));
    }

    public function categoryDto(BlogCategory $category, bool $withChildren = false, bool $activeChildrenOnly = true): BlogCategoryDTO
    {
        $children = null;
        if ($withChildren) {
            $query = BlogCategory::query()->where('parent_id', $category->id)->orderBy('sort_order')->orderBy('name')->orderBy('id');
            if ($activeChildrenOnly) {
                $query->where('is_active', true);
            }
            $childModels = $query->get();
        }
        $mediaMap = $this->hydrator->mediaMap([$category->media_id, ...(isset($childModels) ? $childModels->pluck('media_id')->all() : [])]);
        if (isset($childModels)) {
            $children = $childModels->map(fn (BlogCategory $c) => BlogCategoryDTO::fromModel($c, $this->hydrator->mediaDto($mediaMap, $c->media_id)))->values()->all();
        }

        return BlogCategoryDTO::fromModel($category, $this->hydrator->mediaDto($mediaMap, $category->media_id), null, $children);
    }

    // ── tags ────────────────────────────────────────────────────────────────

    public function paginateTags(array $filters, int $perPage, bool $activeOnly): LengthAwarePaginator
    {
        $query = BlogTag::query();
        if ($activeOnly) {
            $query->where('is_active', true);
        }
        $this->applyNameFilters($query, $filters, 'name');
        $query->orderBy('name')->orderBy('id');

        $paginator = $query->paginate($perPage)->withQueryString();

        return $paginator->setCollection($paginator->getCollection()->map(fn (BlogTag $t) => BlogTagDTO::fromModel($t)));
    }

    // ── authors ─────────────────────────────────────────────────────────────

    public function paginateAuthors(array $filters, int $perPage, bool $activeOnly): LengthAwarePaginator
    {
        $query = BlogAuthor::query();
        if ($activeOnly) {
            $query->where('is_active', true);
        }
        $this->applyNameFilters($query, $filters, 'display_name');
        $query->orderBy('display_name')->orderBy('id');

        $paginator = $query->paginate($perPage)->withQueryString();
        $mediaMap = $this->hydrator->mediaMap($paginator->getCollection()->pluck('media_id')->all());

        return $paginator->setCollection($paginator->getCollection()->map(
            fn (BlogAuthor $a) => BlogAuthorDTO::fromModel($a, $this->hydrator->mediaDto($mediaMap, $a->media_id))
        ));
    }

    public function authorDto(BlogAuthor $author): BlogAuthorDTO
    {
        $mediaMap = $this->hydrator->mediaMap([$author->media_id]);

        return BlogAuthorDTO::fromModel($author, $this->hydrator->mediaDto($mediaMap, $author->media_id));
    }

    // ── collections ─────────────────────────────────────────────────────────

    public function paginateCollections(array $filters, int $perPage, bool $public): LengthAwarePaginator
    {
        $query = BlogCollection::query();
        if ($public) {
            $query->currentlyVisible();
        } elseif (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (filled($filters['search'] ?? null)) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        $query->orderByDesc('created_at')->orderByDesc('id');

        $paginator = $query->paginate($perPage)->withQueryString();

        return $paginator->setCollection($paginator->getCollection()->map(
            fn (BlogCollection $c) => $public ? $this->collectionDto($c, withPosts: false) : $this->collectionDto($c, withPostIds: true)
        ));
    }

    public function collectionDto(BlogCollection $collection, bool $withPosts = false, bool $withPostIds = false, ?int $viewerId = null): BlogCollectionDTO
    {
        $postIds = $collection->posts()->pluck('blog_posts.id')->map(fn ($id) => (int) $id)->all();

        return new BlogCollectionDTO(
            id: (int) $collection->id,
            name: (string) $collection->name,
            slug: (string) $collection->slug,
            description: $collection->description,
            isActive: (bool) $collection->is_active,
            startsAt: $collection->starts_at,
            endsAt: $collection->ends_at,
            createdAt: Carbon::parse($collection->created_at),
            updatedAt: Carbon::parse($collection->updated_at),
            posts: $withPosts ? $this->hydrator->summaries($this->posts->publicPostsInOrder($postIds), $viewerId) : null,
            postIds: $withPostIds ? $postIds : null,
        );
    }

    // ── placements ──────────────────────────────────────────────────────────

    /**
     * Active-window placements for one key, ordered (sort_order, id), with only
     * publicly visible posts — an unpublished placed post is silently skipped.
     *
     * @return list<BlogPlacementDTO>
     */
    public function publicPlacements(string $key, ?int $viewerId, int $limit = 50): array
    {
        $placements = BlogPlacement::query()
            ->where('placement_key', $key)
            ->activeNow()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $posts = $this->posts->publicPostsInOrder($placements->pluck('blog_post_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $summaries = collect($this->hydrator->summaries($posts, $viewerId))->keyBy('id');

        return $placements
            ->filter(fn (BlogPlacement $p) => $summaries->has((int) $p->blog_post_id))
            ->map(fn (BlogPlacement $p) => $this->placementDto($p, $summaries->get((int) $p->blog_post_id)))
            ->values()
            ->all();
    }

    public function paginatePlacementsAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = BlogPlacement::query();
        if (filled($filters['placement_key'] ?? null)) {
            $query->where('placement_key', (string) $filters['placement_key']);
        }
        $query->orderBy('placement_key')->orderBy('sort_order')->orderBy('id');

        $paginator = $query->paginate($perPage)->withQueryString();

        return $paginator->setCollection($paginator->getCollection()->map(fn (BlogPlacement $p) => $this->placementDto($p)));
    }

    public function placementDto(BlogPlacement $placement, ?BlogPostSummaryDTO $post = null): BlogPlacementDTO
    {
        $now = now();

        return new BlogPlacementDTO(
            id: (int) $placement->id,
            placementKey: (string) $placement->placement_key,
            postId: (int) $placement->blog_post_id,
            startsAt: $placement->starts_at,
            endsAt: $placement->ends_at,
            sortOrder: (int) $placement->sort_order,
            isActiveNow: ($placement->starts_at === null || $placement->starts_at->lessThanOrEqualTo($now))
                && ($placement->ends_at === null || $placement->ends_at->greaterThan($now)),
            post: $post,
        );
    }

    private function applyNameFilters(Builder $query, array $filters, string $column): void
    {
        if (filled($filters['search'] ?? null)) {
            $search = (string) $filters['search'];
            $query->where(fn (Builder $q) => $q->where($column, 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
    }
}
