<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Persistence\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Contracts\BlogManagerInterface;
use Modules\Blog\Domain\DTOs\BlogSitemapEntryDTO;
use Modules\Blog\Domain\Enums\BlogSubscriptionSubject;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Blog\Infrastructure\Support\BlogUrls;

final class EloquentBlogManager implements BlogManagerInterface
{
    public function __construct(
        private readonly BlogPostHydrator $hydrator,
    ) {}

    public function getSitemapEntries(): array
    {
        $entries = [];

        BlogPost::query()->publiclyVisible()
            ->orderByDesc('published_at')
            ->select(['id', 'slug', 'published_at', 'updated_at', 'robots_index'])
            ->each(function (BlogPost $post) use (&$entries): void {
                $entries[] = new BlogSitemapEntryDTO(
                    type: 'post',
                    slug: (string) $post->slug,
                    url: BlogUrls::post((string) $post->slug),
                    publishedAt: $post->published_at?->toIso8601String(),
                    updatedAt: Carbon::parse($post->updated_at)->toIso8601String(),
                    indexable: (bool) $post->robots_index,
                );
            });

        foreach (BlogCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $category) {
            $entries[] = new BlogSitemapEntryDTO('category', (string) $category->slug, BlogUrls::category((string) $category->slug), null, Carbon::parse($category->updated_at)->toIso8601String(), true);
        }

        if ((bool) config('blog.sitemap.include_tags', false)) {
            foreach (BlogTag::query()->where('is_active', true)->orderBy('name')->get() as $tag) {
                $entries[] = new BlogSitemapEntryDTO('tag', (string) $tag->slug, BlogUrls::tag((string) $tag->slug), null, Carbon::parse($tag->updated_at)->toIso8601String(), true);
            }
        }

        if ((bool) config('blog.sitemap.include_authors', true)) {
            foreach (BlogAuthor::query()->where('is_active', true)->orderBy('display_name')->get() as $author) {
                $entries[] = new BlogSitemapEntryDTO('author', (string) $author->slug, BlogUrls::author((string) $author->slug), null, Carbon::parse($author->updated_at)->toIso8601String(), true);
            }
        }

        return array_values(array_filter($entries, fn (BlogSitemapEntryDTO $e) => $e->indexable));
    }

    public function getSubscriberUserIds(array $categoryIds, array $tagIds, array $authorIds): array
    {
        $groups = [
            BlogSubscriptionSubject::Category->value => $categoryIds,
            BlogSubscriptionSubject::Tag->value => $tagIds,
            BlogSubscriptionSubject::Author->value => $authorIds,
        ];

        $groups = array_filter($groups, fn (array $ids) => $ids !== []);
        if ($groups === []) {
            return [];
        }

        return DB::table('blog_subscriptions')
            ->where(function ($q) use ($groups): void {
                foreach ($groups as $type => $ids) {
                    $q->orWhere(fn ($g) => $g->where('subject_type', $type)->whereIn('subject_id', array_map('intval', $ids)));
                }
            })
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function getPublishedPostsForProduct(int $productId, int $limit = 6): array
    {
        $posts = BlogPost::query()->publiclyVisible()->select(BlogPost::listColumns())
            ->whereIn('blog_posts.id', fn ($q) => $q->select('blog_post_id')->from('blog_post_products')->where('product_id', $productId))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->with(BlogPostHydrator::SUMMARY_RELATIONS)
            ->get();

        return $this->hydrator->summaries($posts, null);
    }
}
