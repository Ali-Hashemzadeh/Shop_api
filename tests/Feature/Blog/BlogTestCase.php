<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostStat;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\User;
use Modules\Media\Domain\Models\Media;
use Tests\TestCase;

/**
 * Shared fixtures for the Blog suite. Fixtures write Blog's own tables directly
 * (tests are outside the module wall); every behaviour under test goes through
 * the HTTP API, the Actions, or the published contracts.
 */
abstract class BlogTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();
        $this->seedMediaPermissions();
        $this->seedBlogPermissions();
    }

    /** A user holding exactly these permissions and no role. */
    protected function actingWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeAuthor(array $attributes = []): BlogAuthor
    {
        $name = $attributes['display_name'] ?? 'Author '.Str::random(5);

        return BlogAuthor::query()->create([
            'display_name' => $name,
            'slug' => $attributes['slug'] ?? Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    protected function makeCategory(array $attributes = []): BlogCategory
    {
        $name = $attributes['name'] ?? 'Category '.Str::random(5);

        return BlogCategory::query()->create([
            'name' => $name,
            'slug' => $attributes['slug'] ?? Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_active' => true,
            'sort_order' => 0,
            ...$attributes,
        ]);
    }

    protected function makeTag(array $attributes = []): BlogTag
    {
        $name = $attributes['name'] ?? 'Tag '.Str::random(5);

        return BlogTag::query()->create([
            'name' => $name,
            'slug' => $attributes['slug'] ?? Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * A post in the given state. `published` posts default to a past
     * published_at so they are publicly visible.
     *
     * @param  array{categories?: list<BlogCategory>, tags?: list<BlogTag>, authors?: list<BlogAuthor>}  $relations
     */
    protected function makePost(BlogPostStatus $status = BlogPostStatus::Published, array $attributes = [], array $relations = []): BlogPost
    {
        $title = $attributes['title'] ?? 'Post '.Str::random(6);
        $published = $status === BlogPostStatus::Published;

        $post = BlogPost::createWithPublicCode([
            'title' => $title,
            'slug' => $attributes['slug'] ?? Str::slug($title).'-'.Str::lower(Str::random(5)),
            'excerpt' => 'Excerpt of '.$title,
            'content' => '<p>Body of '.$title.'</p>',
            'search_text' => mb_strtolower($title.' excerpt of '.$title.' body of '.$title),
            'status' => $status->value,
            'published_at' => $published ? now()->subHour() : null,
            'first_published_at' => $published ? now()->subHour() : null,
            'last_published_at' => $published ? now()->subHour() : null,
            'allow_comments' => true,
            'robots_index' => true,
            'robots_follow' => true,
            'version' => 1,
            ...$attributes,
        ]);

        BlogPostStat::query()->firstOrCreate(['blog_post_id' => $post->id]);

        foreach ($relations['categories'] ?? [] as $i => $category) {
            $post->categories()->attach($category->id, ['is_primary' => $i === 0, 'sort_order' => $i]);
        }
        foreach ($relations['tags'] ?? [] as $tag) {
            $post->tags()->attach($tag->id);
        }
        $authors = $relations['authors'] ?? [];
        foreach ($authors as $i => $author) {
            $post->authors()->attach($author->id, ['role' => 'author', 'sort_order' => $i]);
        }
        if ($authors !== []) {
            $post->primary_author_id = $authors[0]->id;
            $post->saveQuietly();
        }

        return $post->fresh();
    }

    protected function makeMedia(?int $ownerId = null, string $path = 'blog/image.jpg'): Media
    {
        return Media::query()->create([
            'uploaded_by_user_id' => $ownerId,
            'file_path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'original_name' => basename($path),
        ]);
    }

    protected function makeProduct(string $title = 'Phone', string $status = 'published', int $price = 10_000_000): Product
    {
        $product = Product::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'status' => $status,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.$product->id.'-'.Str::random(4),
            'type' => 'color',
            'is_default' => true,
            'base_price' => $price,
        ]);

        return $product->fresh();
    }

    /** Minimal valid create payload. */
    protected function postPayload(BlogAuthor $author, array $overrides = []): array
    {
        return [
            'title' => 'A guide to choosing a phone',
            'excerpt' => 'Short summary',
            'content' => '<h2>Intro</h2><p>Hello <strong>world</strong></p>',
            'authors' => [['author_id' => $author->id, 'role' => 'author']],
            ...$overrides,
        ];
    }
}
