<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Persistence\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\DTOs\AdminBlogPostDTO;
use Modules\Blog\Domain\DTOs\BlogAuthorDTO;
use Modules\Blog\Domain\DTOs\BlogCategoryDTO;
use Modules\Blog\Domain\DTOs\BlogMediaDTO;
use Modules\Blog\Domain\DTOs\BlogPostDetailDTO;
use Modules\Blog\Domain\DTOs\BlogPostSummaryDTO;
use Modules\Blog\Domain\DTOs\BlogRelatedProductDTO;
use Modules\Blog\Domain\DTOs\BlogSeoDTO;
use Modules\Blog\Domain\DTOs\BlogTagDTO;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostMedia;
use Modules\Blog\Domain\Models\BlogPostProduct;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Blog\Infrastructure\Support\BlogUrls;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;

/**
 * Turns BlogPost models into DTOs with page-wide batching — the module's N+1
 * guard. For any set of posts it issues a fixed number of queries regardless of
 * the set size:
 *
 *  - Blog-owned relations are eager-loaded with {@see SUMMARY_RELATIONS};
 *  - every Media id on the page (featured, OG, author avatars, post media) is
 *    resolved in ONE MediaManagerInterface::getMediaCollection() call;
 *  - the viewer's likes and bookmarks are two `whereIn` queries;
 *  - related products are ONE CatalogManagerInterface::getPublishedProductsByIds()
 *    call.
 *
 * Public shapes drop inactive categories/tags/authors; admin shapes keep them.
 */
final class BlogPostHydrator
{
    /** Relations every summary needs; load them before calling summaries(). */
    public const SUMMARY_RELATIONS = ['categories', 'tags', 'authors', 'stats'];

    public function __construct(
        private readonly MediaManagerInterface $media,
        private readonly CatalogManagerInterface $catalog,
        private readonly BlogCategoryTree $categoryTree,
    ) {}

    /**
     * @param  iterable<BlogPost>  $posts
     * @return list<BlogPostSummaryDTO>
     */
    public function summaries(iterable $posts, ?int $viewerId): array
    {
        $posts = new EloquentCollection(collect($posts)->values()->all());

        if ($posts->isEmpty()) {
            return [];
        }

        $posts->loadMissing(self::SUMMARY_RELATIONS);

        $mediaMap = $this->mediaMap($posts->flatMap(fn (BlogPost $p) => $this->summaryMediaIds($p))->all());
        [$liked, $bookmarked] = $this->viewerState($posts->pluck('id')->map(fn ($id) => (int) $id)->all(), $viewerId);

        return $posts
            ->map(fn (BlogPost $post) => $this->summary($post, $mediaMap, $liked, $bookmarked, public: true))
            ->all();
    }

    public function detail(
        BlogPost $post,
        ?int $viewerId,
        ?string $redirectedFrom = null,
        bool $isPreview = false,
    ): BlogPostDetailDTO {
        $post->loadMissing([...self::SUMMARY_RELATIONS, 'media', 'products']);

        $mediaIds = [
            ...$this->summaryMediaIds($post),
            $post->og_media_id,
            ...$post->media->pluck('media_id')->all(),
        ];
        $mediaMap = $this->mediaMap($mediaIds);
        [$liked, $bookmarked] = $this->viewerState([(int) $post->id], $viewerId);

        $summary = $this->summary($post, $mediaMap, $liked, $bookmarked, public: true);

        return new BlogPostDetailDTO(
            summary: $summary,
            content: (string) $post->content,
            firstPublishedAt: $post->first_published_at,
            lastPublishedAt: $post->last_published_at,
            media: $this->postMedia($post, $mediaMap),
            relatedProducts: $this->relatedProducts($post),
            seo: $this->seo($post, $mediaMap),
            breadcrumbs: $this->breadcrumbs($post),
            redirectedFrom: $redirectedFrom,
            isPreview: $isPreview,
            status: $isPreview ? $post->status->value : null,
        );
    }

    /**
     * @param  iterable<BlogPost>  $posts
     * @return list<AdminBlogPostDTO>
     */
    public function adminList(iterable $posts): array
    {
        $posts = new EloquentCollection(collect($posts)->values()->all());

        if ($posts->isEmpty()) {
            return [];
        }

        $posts->loadMissing(self::SUMMARY_RELATIONS);
        $mediaMap = $this->mediaMap($posts->flatMap(fn (BlogPost $p) => [...$this->summaryMediaIds($p), $p->og_media_id])->all());

        return $posts->map(fn (BlogPost $post) => $this->admin($post, $mediaMap, withDetail: false))->all();
    }

    public function adminDetail(BlogPost $post): AdminBlogPostDTO
    {
        $post->loadMissing([...self::SUMMARY_RELATIONS, 'media', 'products', 'relatedPosts']);

        $mediaMap = $this->mediaMap([
            ...$this->summaryMediaIds($post),
            $post->og_media_id,
            ...$post->media->pluck('media_id')->all(),
        ]);

        return $this->admin($post, $mediaMap, withDetail: true);
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * @param  array<int, MediaDTO>  $mediaMap
     * @param  array<int, true>  $liked
     * @param  array<int, true>  $bookmarked
     */
    private function summary(BlogPost $post, array $mediaMap, array $liked, array $bookmarked, bool $public): BlogPostSummaryDTO
    {
        $stats = $post->stats;

        return new BlogPostSummaryDTO(
            id: (int) $post->id,
            publicCode: (string) $post->public_code,
            title: (string) $post->title,
            slug: (string) $post->slug,
            excerpt: $post->excerpt,
            featuredMedia: $this->mediaDto($mediaMap, $post->featured_media_id),
            authors: $this->authors($post, $mediaMap, $public),
            categories: $this->categories($post, $public),
            tags: $this->tags($post, $public),
            publishedAt: $post->published_at,
            updatedAt: Carbon::parse($post->updated_at),
            isFeatured: (bool) $post->is_featured,
            allowComments: (bool) $post->allow_comments,
            likesCount: (int) ($stats?->likes_count ?? 0),
            commentsCount: (int) ($stats?->comments_count ?? 0),
            viewsCount: (int) ($stats?->views_count ?? 0),
            isLiked: isset($liked[(int) $post->id]),
            isBookmarked: isset($bookmarked[(int) $post->id]),
        );
    }

    /** @param  array<int, MediaDTO>  $mediaMap */
    private function admin(BlogPost $post, array $mediaMap, bool $withDetail): AdminBlogPostDTO
    {
        $stats = $post->stats;

        return new AdminBlogPostDTO(
            id: (int) $post->id,
            publicCode: (string) $post->public_code,
            title: (string) $post->title,
            slug: (string) $post->slug,
            excerpt: $post->excerpt,
            content: $withDetail ? (string) $post->content : null,
            status: $post->status->value,
            publishedAt: $post->published_at,
            firstPublishedAt: $post->first_published_at,
            lastPublishedAt: $post->last_published_at,
            unpublishAt: $post->unpublish_at,
            isFeatured: (bool) $post->is_featured,
            allowComments: (bool) $post->allow_comments,
            featuredMedia: $this->mediaDto($mediaMap, $post->featured_media_id),
            ogMedia: $this->mediaDto($mediaMap, $post->og_media_id),
            seo: [
                'seo_title' => $post->seo_title,
                'seo_description' => $post->seo_description,
                'canonical_url' => $post->canonical_url,
                'og_title' => $post->og_title,
                'og_description' => $post->og_description,
                'og_media_id' => $post->og_media_id,
                'robots_index' => (bool) $post->robots_index,
                'robots_follow' => (bool) $post->robots_follow,
            ],
            primaryAuthorId: $post->primary_author_id,
            authors: $this->authors($post, $mediaMap, public: false),
            categories: $this->categories($post, public: false),
            tags: $this->tags($post, public: false),
            media: $withDetail ? $this->postMedia($post, $mediaMap) : [],
            products: $withDetail
                ? $post->products->map(fn (BlogPostProduct $p): array => [
                    'product_id' => (int) $p->product_id,
                    'relation_type' => (string) $p->relation_type,
                    'sort_order' => (int) $p->sort_order,
                ])->values()->all()
                : [],
            relatedPostIds: $withDetail ? $post->relatedPosts->pluck('id')->map(fn ($id) => (int) $id)->values()->all() : [],
            stats: [
                'views_count' => (int) ($stats?->views_count ?? 0),
                'likes_count' => (int) ($stats?->likes_count ?? 0),
                'bookmarks_count' => (int) ($stats?->bookmarks_count ?? 0),
                'comments_count' => (int) ($stats?->comments_count ?? 0),
                'product_clicks_count' => (int) ($stats?->product_clicks_count ?? 0),
            ],
            isPubliclyVisible: $post->isPubliclyVisible(),
            createdByUserId: $post->created_by_user_id !== null ? (int) $post->created_by_user_id : null,
            updatedByUserId: $post->updated_by_user_id !== null ? (int) $post->updated_by_user_id : null,
            version: (int) $post->version,
            createdAt: Carbon::parse($post->created_at),
            updatedAt: Carbon::parse($post->updated_at),
            deletedAt: $post->deleted_at !== null ? Carbon::parse($post->deleted_at) : null,
        );
    }

    /**
     * @param  array<int, MediaDTO>  $mediaMap
     * @return list<BlogAuthorDTO>
     */
    private function authors(BlogPost $post, array $mediaMap, bool $public): array
    {
        return $post->authors
            ->filter(fn (BlogAuthor $a) => ! $public || $a->is_active)
            ->map(fn (BlogAuthor $a) => BlogAuthorDTO::fromModel(
                $a,
                $this->mediaDto($mediaMap, $a->media_id),
                (string) $a->pivot->role,
                (int) $a->pivot->sort_order,
            ))
            ->values()
            ->all();
    }

    /** @return list<BlogCategoryDTO> */
    private function categories(BlogPost $post, bool $public): array
    {
        return $post->categories
            ->filter(fn (BlogCategory $c) => ! $public || $c->is_active)
            ->map(fn (BlogCategory $c) => BlogCategoryDTO::fromModel($c, null, (bool) $c->pivot->is_primary))
            ->values()
            ->all();
    }

    /** @return list<BlogTagDTO> */
    private function tags(BlogPost $post, bool $public): array
    {
        return $post->tags
            ->filter(fn (BlogTag $t) => ! $public || $t->is_active)
            ->map(fn (BlogTag $t) => BlogTagDTO::fromModel($t))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, MediaDTO>  $mediaMap
     * @return list<BlogMediaDTO>
     */
    private function postMedia(BlogPost $post, array $mediaMap): array
    {
        return $post->media
            ->map(function (BlogPostMedia $row) use ($mediaMap): ?BlogMediaDTO {
                $media = $mediaMap[(int) $row->media_id] ?? null;

                return $media === null ? null : BlogMediaDTO::fromMedia(
                    $media,
                    $row->alt_text,
                    $row->caption,
                    (string) $row->role,
                    (int) $row->sort_order,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<BlogRelatedProductDTO> */
    private function relatedProducts(BlogPost $post): array
    {
        $links = $post->products;

        if ($links->isEmpty()) {
            return [];
        }

        // One batched Catalog call; unpublished/missing products are simply absent.
        $products = $this->catalog->getPublishedProductsByIds($links->pluck('product_id')->map(fn ($id) => (int) $id)->all());

        return $links
            ->map(fn (BlogPostProduct $link) => isset($products[(int) $link->product_id])
                ? BlogRelatedProductDTO::fromProduct($products[(int) $link->product_id], (string) $link->relation_type, (int) $link->sort_order)
                : null)
            ->filter()
            ->values()
            ->all();
    }

    /** @param  array<int, MediaDTO>  $mediaMap */
    private function seo(BlogPost $post, array $mediaMap): BlogSeoDTO
    {
        $title = $post->seo_title ?: (string) $post->title;
        $description = $post->seo_description ?: $post->excerpt;
        $ogMedia = $this->mediaDto($mediaMap, $post->og_media_id) ?? $this->mediaDto($mediaMap, $post->featured_media_id);

        return new BlogSeoDTO(
            title: $title,
            description: $description,
            canonicalUrl: $post->canonical_url ?: BlogUrls::post((string) $post->slug),
            ogTitle: $post->og_title ?: $title,
            ogDescription: $post->og_description ?: $description,
            ogImage: $ogMedia,
            robotsIndex: (bool) $post->robots_index,
            robotsFollow: (bool) $post->robots_follow,
        );
    }

    /** @return list<array{name: string, slug: string}> root → primary category */
    private function breadcrumbs(BlogPost $post): array
    {
        $primary = $post->categories->first(fn (BlogCategory $c) => (bool) $c->pivot->is_primary)
            ?? $post->categories->first();

        if ($primary === null) {
            return [];
        }

        return collect($this->categoryTree->ancestry((int) $primary->id))
            ->filter(fn (array $node) => $node['is_active'])
            ->map(fn (array $node) => ['name' => $node['name'], 'slug' => $node['slug']])
            ->values()
            ->all();
    }

    /** @return list<int|null> */
    private function summaryMediaIds(BlogPost $post): array
    {
        return [
            $post->featured_media_id,
            ...$post->authors->pluck('media_id')->all(),
        ];
    }

    /**
     * @param  list<int|null>  $ids
     * @return array<int, MediaDTO>
     */
    public function mediaMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => $id === null ? 0 : (int) $id,
            $ids,
        ))));

        if ($ids === []) {
            return [];
        }

        return $this->media->getMediaCollection($ids)
            ->keyBy(fn (MediaDTO $m) => $m->id)
            ->all();
    }

    /** @param  array<int, MediaDTO>  $mediaMap */
    public function mediaDto(array $mediaMap, ?int $mediaId): ?BlogMediaDTO
    {
        if ($mediaId === null || ! isset($mediaMap[$mediaId])) {
            return null;
        }

        return BlogMediaDTO::fromMedia($mediaMap[$mediaId]);
    }

    /**
     * The viewer's likes/bookmarks among these posts, as id sets.
     *
     * @param  list<int>  $postIds
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function viewerState(array $postIds, ?int $viewerId): array
    {
        if ($viewerId === null || $postIds === []) {
            return [[], []];
        }

        $liked = DB::table('blog_post_likes')->where('user_id', $viewerId)->whereIn('blog_post_id', $postIds)->pluck('blog_post_id');
        $bookmarked = DB::table('blog_post_bookmarks')->where('user_id', $viewerId)->whereIn('blog_post_id', $postIds)->pluck('blog_post_id');

        return [$this->idSet($liked), $this->idSet($bookmarked)];
    }

    /** @return array<int, true> */
    private function idSet(Collection $ids): array
    {
        return $ids->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }
}
