<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Support;

use Modules\Blog\Domain\Contracts\BlogHtmlSanitizerInterface;
use Modules\Blog\Domain\Enums\BlogAuthorRole;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostMedia;
use Modules\Blog\Domain\Models\BlogPostProduct;
use Modules\Blog\Domain\Models\BlogPostSlugHistory;
use Modules\Blog\Domain\Services\PersianTextNormalizer;

/**
 * The shared write mechanics of post create / update / revision restore.
 * Every method runs inside the caller's transaction, so a post and all of its
 * attachments commit (or roll back) together.
 *
 * Input arrays are validated request data. A `*_ids`/attachment key that is
 * absent means "leave as is"; present-but-empty means "remove all".
 */
final class BlogPostPersister
{
    /** Scalar columns an editor may write. Never public_code, status, or version. */
    private const ATTRIBUTES = [
        'title', 'excerpt', 'featured_media_id', 'og_media_id', 'seo_title', 'seo_description',
        'canonical_url', 'og_title', 'og_description', 'robots_index', 'robots_follow',
        'allow_comments', 'is_featured', 'unpublish_at',
    ];

    public function __construct(
        private readonly BlogHtmlSanitizerInterface $sanitizer,
    ) {}

    /**
     * Fill editable scalar attributes (content is sanitized server-side before it
     * ever touches the model) and refresh the derived search text.
     *
     * @return list<string> the attribute keys whose value actually changed
     */
    public function applyAttributes(BlogPost $post, array $data): array
    {
        foreach (self::ATTRIBUTES as $key) {
            if (array_key_exists($key, $data)) {
                $post->setAttribute($key, $data[$key]);
            }
        }

        if (array_key_exists('content', $data)) {
            $post->content = $this->sanitizer->sanitize((string) ($data['content'] ?? ''));
        }

        $dirty = array_keys($post->getDirty());

        if (array_intersect($dirty, ['title', 'excerpt', 'content']) !== [] || $post->search_text === null) {
            $post->search_text = PersianTextNormalizer::normalize(implode(' ', [
                (string) $post->title,
                PersianTextNormalizer::normalize((string) $post->excerpt),
                PersianTextNormalizer::searchableTextFromHtml($post->content),
            ]));
        }

        return array_values(array_diff($dirty, ['search_text']));
    }

    /**
     * Change the slug. Once a post has ever been public its old slug is kept in
     * the slug history so existing links keep resolving; reclaiming one of its
     * own retired slugs removes that history row, so the current slug is never
     * also a "redirect from" (no loops).
     *
     * @return string|null the previous slug when it changed
     */
    public function applySlug(BlogPost $post, string $slug): ?string
    {
        $previous = $post->exists ? (string) $post->getOriginal('slug') : null;

        if ($previous === $slug) {
            return null;
        }

        $post->slug = $slug;

        if ($previous === null) {
            return null;
        }

        BlogPostSlugHistory::query()->where('blog_post_id', $post->id)->where('slug', $slug)->delete();

        if ($post->first_published_at !== null) {
            BlogPostSlugHistory::query()->firstOrCreate(['slug' => $previous], ['blog_post_id' => $post->id]);
        }

        return $previous;
    }

    /**
     * Exactly one primary category whenever the post has categories: the given
     * primary id, else the first listed category.
     *
     * @param  list<int>  $categoryIds
     */
    public function syncCategories(BlogPost $post, array $categoryIds, ?int $primaryId): void
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $primaryId = $primaryId !== null && in_array($primaryId, $categoryIds, true) ? $primaryId : ($categoryIds[0] ?? null);

        $sync = [];
        foreach ($categoryIds as $index => $id) {
            $sync[$id] = ['is_primary' => $id === $primaryId, 'sort_order' => $index];
        }

        $post->categories()->sync($sync);
    }

    /** @param  list<int>  $tagIds */
    public function syncTags(BlogPost $post, array $tagIds): void
    {
        $post->tags()->sync(array_values(array_unique(array_map('intval', $tagIds))));
    }

    /**
     * Ordered co-authorship. `primary_author_id` is kept consistent with the
     * pivot: the first entry with role `author`, else the first entry.
     *
     * @param  list<array{author_id: int, role?: string|null}>  $authors
     */
    public function syncAuthors(BlogPost $post, array $authors): void
    {
        $sync = [];
        $primary = null;

        foreach (array_values($authors) as $index => $entry) {
            $id = (int) $entry['author_id'];
            if (isset($sync[$id])) {
                continue;
            }
            $role = (string) ($entry['role'] ?? BlogAuthorRole::Author->value);
            $sync[$id] = ['role' => $role, 'sort_order' => $index];

            if ($primary === null && $role === BlogAuthorRole::Author->value) {
                $primary = $id;
            }
        }

        $post->authors()->sync($sync);
        $post->primary_author_id = $primary ?? (array_key_first($sync) ?? null);
        $post->saveQuietly();
    }

    /** @param  list<array{media_id: int, role?: string|null, alt_text?: string|null, caption?: string|null}>  $media */
    public function syncMedia(BlogPost $post, array $media): void
    {
        BlogPostMedia::query()->where('blog_post_id', $post->id)->delete();

        foreach (array_values($media) as $index => $row) {
            BlogPostMedia::query()->create([
                'blog_post_id' => $post->id,
                'media_id' => (int) $row['media_id'],
                'role' => (string) ($row['role'] ?? 'inline'),
                'alt_text' => $row['alt_text'] ?? null,
                'caption' => $row['caption'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    /** @param  list<array{product_id: int, relation_type?: string|null}>  $products */
    public function syncProducts(BlogPost $post, array $products): void
    {
        BlogPostProduct::query()->where('blog_post_id', $post->id)->delete();

        $seen = [];
        foreach (array_values($products) as $index => $row) {
            $productId = (int) $row['product_id'];
            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;

            BlogPostProduct::query()->insert([
                'blog_post_id' => $post->id,
                'product_id' => $productId,
                'relation_type' => (string) ($row['relation_type'] ?? 'mentioned'),
                'sort_order' => $index,
            ]);
        }
    }

    /** @param  list<int>  $relatedIds */
    public function syncRelatedPosts(BlogPost $post, array $relatedIds): void
    {
        $sync = [];
        foreach (array_values(array_unique(array_map('intval', $relatedIds))) as $index => $id) {
            if ($id !== (int) $post->id) {
                $sync[$id] = ['sort_order' => $index];
            }
        }

        $post->relatedPosts()->sync($sync);
    }

    /** Apply every relation present in the validated payload. */
    public function syncRelations(BlogPost $post, array $data): void
    {
        if (array_key_exists('category_ids', $data)) {
            $this->syncCategories($post, (array) $data['category_ids'], isset($data['primary_category_id']) ? (int) $data['primary_category_id'] : null);
        } elseif (array_key_exists('primary_category_id', $data) && $data['primary_category_id'] !== null) {
            $current = $post->categories()->pluck('blog_categories.id')->map(fn ($id) => (int) $id)->all();
            $this->syncCategories($post, $current, (int) $data['primary_category_id']);
        }
        if (array_key_exists('tag_ids', $data)) {
            $this->syncTags($post, (array) $data['tag_ids']);
        }
        if (array_key_exists('authors', $data)) {
            $this->syncAuthors($post, (array) $data['authors']);
        }
        if (array_key_exists('media', $data)) {
            $this->syncMedia($post, (array) $data['media']);
        }
        if (array_key_exists('products', $data)) {
            $this->syncProducts($post, (array) $data['products']);
        }
        if (array_key_exists('related_post_ids', $data)) {
            $this->syncRelatedPosts($post, (array) $data['related_post_ids']);
        }
    }

    /** Whether the payload touches anything the revision snapshot captures. */
    public static function touchesRevisionedContent(array $changedAttributes, array $data, bool $slugChanged): bool
    {
        $revisioned = ['title', 'excerpt', 'content', 'featured_media_id', 'og_media_id', 'seo_title', 'seo_description',
            'canonical_url', 'og_title', 'og_description', 'robots_index', 'robots_follow'];

        return $slugChanged
            || array_intersect($changedAttributes, $revisioned) !== []
            || array_intersect(array_keys($data), ['category_ids', 'primary_category_id', 'tag_ids', 'authors']) !== [];
    }
}
