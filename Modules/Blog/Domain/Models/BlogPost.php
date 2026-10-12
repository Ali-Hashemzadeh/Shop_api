<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Blog\Domain\Enums\BlogPostStatus;

/**
 * A Blog article. PRIVATE to the Blog module.
 *
 * The public handle is `public_code` (`bdb-XXXXXX`, minted by HasPublicCode and
 * never mass-assignable); storefront URLs use the slug. The integer id stays the
 * structural key for every pivot, revision and admin route. Media and user
 * columns are loose references to other modules — no relation, no FK.
 */
class BlogPost extends Model
{
    use HasPublicCode;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'search_text',
        'featured_media_id',
        'primary_author_id',
        'status',
        'published_at',
        'first_published_at',
        'last_published_at',
        'unpublish_at',
        'is_featured',
        'allow_comments',
        'seo_title',
        'seo_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_media_id',
        'robots_index',
        'robots_follow',
        'created_by_user_id',
        'updated_by_user_id',
        'version',
    ];

    protected $hidden = ['search_text'];

    protected $casts = [
        'status' => BlogPostStatus::class,
        'published_at' => 'datetime',
        'first_published_at' => 'datetime',
        'last_published_at' => 'datetime',
        'unpublish_at' => 'datetime',
        'is_featured' => 'boolean',
        'allow_comments' => 'boolean',
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
        'featured_media_id' => 'integer',
        'og_media_id' => 'integer',
        'primary_author_id' => 'integer',
        'version' => 'integer',
    ];

    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::BlogPost;
    }

    /**
     * Qualified columns for list/card queries: everything except the article
     * HTML and its search projection, so listings never fetch LONGTEXT bodies.
     *
     * @return list<string>
     */
    public static function listColumns(): array
    {
        return array_map(fn (string $column) => 'blog_posts.'.$column, [
            'id', 'public_code', 'title', 'slug', 'excerpt', 'featured_media_id', 'primary_author_id', 'status',
            'published_at', 'first_published_at', 'last_published_at', 'unpublish_at', 'is_featured',
            'allow_comments', 'seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description',
            'og_media_id', 'robots_index', 'robots_follow', 'created_by_user_id', 'updated_by_user_id', 'version',
            'deleted_at', 'created_at', 'updated_at',
        ]);
    }

    /**
     * The single definition of "publicly visible": published, live, not yet
     * unpublished. Scheduled/draft/in-review/approved/archived and soft-deleted
     * posts can never satisfy it. The unpublish_at guard hides an expiring post
     * on time even before the scheduler runs.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('blog_posts.status', BlogPostStatus::Published->value)
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', $now)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('blog_posts.unpublish_at')->orWhere('blog_posts.unpublish_at', '>', $now);
            });
    }

    public function isPubliclyVisible(): bool
    {
        $now = now();

        return $this->status === BlogPostStatus::Published
            && $this->deleted_at === null
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo($now)
            && ($this->unpublish_at === null || $this->unpublish_at->greaterThan($now));
    }

    /** @return BelongsToMany<BlogCategory> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(BlogCategory::class, 'blog_post_category')
            ->withPivot(['is_primary', 'sort_order'])
            ->orderByPivot('sort_order');
    }

    /** @return BelongsToMany<BlogTag> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag')->orderBy('blog_tags.name');
    }

    /** @return BelongsToMany<BlogAuthor> */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(BlogAuthor::class, 'blog_post_author')
            ->withPivot(['role', 'sort_order'])
            ->orderByPivot('sort_order');
    }

    /** @return BelongsTo<BlogAuthor, self> */
    public function primaryAuthor(): BelongsTo
    {
        return $this->belongsTo(BlogAuthor::class, 'primary_author_id');
    }

    /** @return HasMany<BlogPostMedia> */
    public function media(): HasMany
    {
        return $this->hasMany(BlogPostMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<BlogPostProduct> */
    public function products(): HasMany
    {
        return $this->hasMany(BlogPostProduct::class)->orderBy('sort_order');
    }

    /** @return BelongsToMany<self> */
    public function relatedPosts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'blog_related_posts', 'blog_post_id', 'related_blog_post_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /** @return HasOne<BlogPostStat> */
    public function stats(): HasOne
    {
        return $this->hasOne(BlogPostStat::class);
    }

    /** @return HasMany<BlogPostRevision> */
    public function revisions(): HasMany
    {
        return $this->hasMany(BlogPostRevision::class);
    }

    /** @return HasMany<BlogPostActivity> */
    public function activity(): HasMany
    {
        return $this->hasMany(BlogPostActivity::class);
    }

    /** @return HasMany<BlogEditorialNote> */
    public function editorialNotes(): HasMany
    {
        return $this->hasMany(BlogEditorialNote::class);
    }

    /** @return HasMany<BlogPostSlugHistory> */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(BlogPostSlugHistory::class);
    }
}
