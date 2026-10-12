<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Blog\Domain\Contracts\BlogManagerInterface;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogEditorialNote;
use Modules\Blog\Domain\Models\BlogPostSlugHistory;
use Modules\Blog\Domain\Models\BlogPostStat;
use Modules\Identity\Domain\Models\User;

/**
 * The public storefront surface: nothing unpublished ever leaks, the detail
 * shape carries SEO + structured data, search/filter/sort behave, retired slugs
 * redirect, feeds/sitemaps contain published posts only, and listing cost does
 * not grow with page size.
 */
class BlogPublicReadTest extends BlogTestCase
{
    /** @test */
    public function only_live_published_posts_are_public(): void
    {
        $visible = $this->makePost(BlogPostStatus::Published, ['title' => 'Visible']);
        $hidden = [
            $this->makePost(BlogPostStatus::Draft),
            $this->makePost(BlogPostStatus::InReview),
            $this->makePost(BlogPostStatus::Approved),
            $this->makePost(BlogPostStatus::Scheduled, ['published_at' => now()->addDay()]),
            $this->makePost(BlogPostStatus::Archived),
            $this->makePost(BlogPostStatus::Published, ['published_at' => now()->addDay()]),
            $this->makePost(BlogPostStatus::Published, ['unpublish_at' => now()->subMinute()]),
        ];
        $deleted = $this->makePost(BlogPostStatus::Published);
        $deleted->delete();
        $hidden[] = $deleted;

        $response = $this->getJson('/api/v1/blog/posts')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $visible->public_code);

        foreach ($hidden as $post) {
            $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();
            $this->getJson("/api/v1/blog/posts/{$post->public_code}")->assertNotFound();
        }

        // Even a status filter cannot widen the public set.
        $this->getJson('/api/v1/blog/posts?status=draft')->assertJsonCount(1, 'data');
    }

    /** @test */
    public function the_detail_exposes_content_seo_and_structured_data_but_no_internals(): void
    {
        $author = $this->makeAuthor(['display_name' => 'Sara', 'user_id' => User::factory()->create()->id]);
        $category = $this->makeCategory(['name' => 'Guides', 'slug' => 'guides']);
        $tag = $this->makeTag(['name' => 'Phones']);
        $hero = $this->makeMedia();
        $post = $this->makePost(BlogPostStatus::Published, [
            'title' => 'Best phones',
            'excerpt' => 'Our picks',
            'content' => '<h2>Intro</h2><p>Text</p>',
            'featured_media_id' => $hero->id,
            'created_by_user_id' => 77,
        ], ['categories' => [$category], 'tags' => [$tag], 'authors' => [$author]]);
        BlogEditorialNote::query()->create(['blog_post_id' => $post->id, 'user_id' => 1, 'body' => 'SECRET NOTE']);

        $response = $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk();

        $response->assertJsonPath('data.id', $post->public_code)
            ->assertJsonPath('data.content', '<h2>Intro</h2><p>Text</p>')
            ->assertJsonPath('data.authors.0.display_name', 'Sara')
            ->assertJsonPath('data.categories.0.slug', 'guides')
            ->assertJsonPath('data.categories.0.is_primary', true)
            ->assertJsonPath('data.tags.0.name', 'Phones')
            ->assertJsonPath('data.featured_media.id', $hero->id)
            ->assertJsonPath('data.likes_count', 0)
            ->assertJsonPath('data.is_liked', false)
            ->assertJsonPath('data.is_bookmarked', false)
            ->assertJsonPath('data.seo.title', 'Best phones')
            ->assertJsonPath('data.seo.description', 'Our picks')
            ->assertJsonPath('data.seo.og_title', 'Best phones')
            ->assertJsonPath('data.seo.og_media.id', $hero->id)
            ->assertJsonPath('data.seo.robots', 'index,follow')
            ->assertJsonPath('data.breadcrumbs.0.slug', 'guides')
            ->assertJsonPath('data.structured_data.article.@type', 'BlogPosting')
            ->assertJsonPath('data.structured_data.article.author.0.name', 'Sara')
            ->assertJsonPath('data.structured_data.breadcrumb.@type', 'BreadcrumbList')
            ->assertJsonPath('data.redirect', null);

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('SECRET NOTE', $json);
        $this->assertStringNotContainsString('user_id', $json);
        $this->assertStringNotContainsString('created_by', $json);
        $this->assertStringNotContainsString('version', $json);
        $this->assertStringNotContainsString('search_text', $json);
    }

    /** @test */
    public function seo_fields_fall_back_in_the_documented_order(): void
    {
        $og = $this->makeMedia(path: 'blog/og.jpg');
        $post = $this->makePost(BlogPostStatus::Published, [
            'title' => 'Title',
            'excerpt' => 'Excerpt',
            'seo_title' => 'SEO Title',
            'og_description' => 'OG Desc',
            'og_media_id' => $og->id,
            'canonical_url' => 'https://example.com/canonical',
            'robots_index' => false,
        ]);

        $this->getJson("/api/v1/blog/posts/{$post->slug}")
            ->assertJsonPath('data.seo.title', 'SEO Title')
            ->assertJsonPath('data.seo.description', 'Excerpt')
            ->assertJsonPath('data.seo.og_title', 'SEO Title')
            ->assertJsonPath('data.seo.og_description', 'OG Desc')
            ->assertJsonPath('data.seo.og_media.id', $og->id)
            ->assertJsonPath('data.seo.canonical_url', 'https://example.com/canonical')
            ->assertJsonPath('data.seo.robots', 'noindex,follow');
    }

    /** @test */
    public function a_retired_slug_resolves_to_the_current_post_with_redirect_info(): void
    {
        $post = $this->makePost(BlogPostStatus::Published, ['slug' => 'new-home']);
        BlogPostSlugHistory::query()->create(['blog_post_id' => $post->id, 'slug' => 'old-home']);

        $this->getJson('/api/v1/blog/posts/old-home')
            ->assertOk()
            ->assertJsonPath('data.slug', 'new-home')
            ->assertJsonPath('data.redirect.from', 'old-home')
            ->assertJsonPath('data.redirect.to', 'new-home')
            ->assertJsonPath('data.redirect.status', 301);

        // An unpublished post's history does not resolve either.
        $post->update(['status' => 'archived']);
        $this->getJson('/api/v1/blog/posts/old-home')->assertNotFound();
    }

    /** @test */
    public function a_public_code_resolves_exactly_and_never_partially(): void
    {
        $post = $this->makePost(BlogPostStatus::Published);
        $lower = strtolower($post->public_code);

        $this->getJson("/api/v1/blog/posts/{$lower}")->assertOk()->assertJsonPath('data.id', $post->public_code);
        $this->getJson('/api/v1/blog/posts?search='.$post->public_code)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/blog/posts?search='.substr($post->public_code, 0, 7))->assertJsonCount(0, 'data');
    }

    /** @test */
    public function search_matches_title_body_and_taxonomy_with_persian_normalization(): void
    {
        $tag = $this->makeTag(['name' => 'Gaming']);
        $this->makePost(BlogPostStatus::Published, ['title' => 'کتاب خوب', 'search_text' => 'کتاب خوب']);
        $this->makePost(BlogPostStatus::Published, ['title' => 'Laptops', 'search_text' => 'laptops battery life tips']);
        $this->makePost(BlogPostStatus::Published, ['title' => 'Consoles', 'search_text' => 'consoles'], ['tags' => [$tag]]);
        $this->makePost(BlogPostStatus::Draft, ['title' => 'Battery draft', 'search_text' => 'battery draft']);

        // Arabic kaf/yeh in the query still match Persian text.
        $this->getJson('/api/v1/blog/posts?search='.urlencode('كتاب'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/blog/posts?search=battery')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Laptops');
        $this->getJson('/api/v1/blog/posts?search=Gaming')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Consoles');
    }

    /** @test */
    public function the_body_search_text_is_derived_from_saved_html(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'title' => 'Plain title',
            'content' => '<p>راهنمای <strong>انتخاب</strong> يخچال</p>',
        ]))->json('data.id');
        $this->postJson("/api/v1/admin/blog/posts/{$id}/submit-review");
        $this->postJson("/api/v1/admin/blog/posts/{$id}/approve");
        $this->postJson("/api/v1/admin/blog/posts/{$id}/publish")->assertOk();

        // Stored HTML is untouched (still the Arabic yeh), search folds it.
        $this->assertStringContainsString('يخچال', DB::table('blog_posts')->where('id', $id)->value('content'));
        $this->getJson('/api/v1/blog/posts?search='.urlencode('یخچال'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/blog/posts?search='.urlencode('انتخاب يخچال'))->assertJsonCount(1, 'data');
    }

    /** @test */
    public function category_filters_include_descendants_and_inactive_taxonomy_is_hidden(): void
    {
        $root = $this->makeCategory(['slug' => 'tech']);
        $child = $this->makeCategory(['slug' => 'phones', 'parent_id' => $root->id]);
        $inactive = $this->makeCategory(['slug' => 'secret', 'is_active' => false]);
        $tag = $this->makeTag(['slug' => 'deals']);
        $author = $this->makeAuthor(['slug' => 'sara']);
        $retired = $this->makeAuthor(['slug' => 'gone', 'is_active' => false]);

        $this->makePost(BlogPostStatus::Published, ['title' => 'In child'], ['categories' => [$child], 'tags' => [$tag], 'authors' => [$author]]);
        $this->makePost(BlogPostStatus::Published, ['title' => 'In root'], ['categories' => [$root, $inactive], 'authors' => [$retired]]);
        $this->makePost(BlogPostStatus::Published, ['title' => 'Elsewhere']);

        $this->getJson('/api/v1/blog/categories/tech/posts')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/blog/categories/phones/posts')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/blog/posts?category=tech')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/blog/categories/secret')->assertNotFound();
        $this->getJson('/api/v1/blog/categories/secret/posts')->assertNotFound();
        $this->getJson('/api/v1/blog/tags/deals/posts')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/blog/authors/sara/posts')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/blog/authors/gone')->assertNotFound();

        // The inactive category and inactive author are dropped from the post card.
        $rootPost = collect($this->getJson('/api/v1/blog/posts')->json('data'))->firstWhere('title', 'In root');
        $this->assertSame(['tech'], array_column($rootPost['categories'], 'slug'));
        $this->assertSame([], $rootPost['authors']);

        // Category tree excludes inactive branches.
        $tree = $this->getJson('/api/v1/blog/categories')->assertOk()->json('data');
        $this->assertSame(['tech'], array_column($tree, 'slug'));
        $this->assertSame('phones', $tree[0]['children'][0]['slug']);
    }

    /** @test */
    public function sorts_use_maintained_counters_and_recency(): void
    {
        $old = $this->makePost(BlogPostStatus::Published, ['title' => 'Old', 'published_at' => now()->subDays(5)]);
        $new = $this->makePost(BlogPostStatus::Published, ['title' => 'New', 'published_at' => now()->subDay()]);
        BlogPostStat::query()->whereKey($old->id)->update(['views_count' => 100, 'comments_count' => 1]);
        BlogPostStat::query()->whereKey($new->id)->update(['views_count' => 5, 'comments_count' => 9, 'likes_count' => 50]);

        $this->getJson('/api/v1/blog/posts')->assertJsonPath('data.0.title', 'New');
        $this->getJson('/api/v1/blog/posts?sort=oldest')->assertJsonPath('data.0.title', 'Old');
        $this->getJson('/api/v1/blog/posts?sort=most_viewed')->assertJsonPath('data.0.title', 'Old');
        $this->getJson('/api/v1/blog/posts?sort=most_commented')->assertJsonPath('data.0.title', 'New');
        $this->getJson('/api/v1/blog/posts?sort=popular')->assertJsonPath('data.0.title', 'New');
        $this->getJson('/api/v1/blog/posts?sort=random')->assertStatus(422);
    }

    /** @test */
    public function pagination_uses_the_standard_envelope_and_bounds(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makePost(BlogPostStatus::Published);
        }

        $this->getJson('/api/v1/blog/posts?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/v1/blog/posts?per_page=101')->assertStatus(422);
        $this->getJson('/api/v1/blog/posts?per_page=0')->assertStatus(422);
    }

    /** @test */
    public function published_date_range_filter(): void
    {
        $this->makePost(BlogPostStatus::Published, ['published_at' => now()->subDays(10)]);
        $this->makePost(BlogPostStatus::Published, ['published_at' => now()->subDay()]);

        $from = now()->subDays(3)->toDateString();
        $this->getJson("/api/v1/blog/posts?published_from={$from}")->assertJsonCount(1, 'data');
    }

    /** @test */
    public function listing_does_not_load_html_and_costs_the_same_for_one_or_many_posts(): void
    {
        $author = $this->makeAuthor(['media_id' => $this->makeMedia()->id]);
        $category = $this->makeCategory();
        $tag = $this->makeTag();
        $this->makePost(BlogPostStatus::Published, ['featured_media_id' => $this->makeMedia()->id], ['categories' => [$category], 'tags' => [$tag], 'authors' => [$author]]);

        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/blog/posts')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $one = $count();
        $this->assertArrayNotHasKey('content', $this->getJson('/api/v1/blog/posts')->json('data.0'));

        foreach (range(1, 9) as $i) {
            $this->makePost(BlogPostStatus::Published, ['featured_media_id' => $this->makeMedia()->id], ['categories' => [$category], 'tags' => [$this->makeTag()], 'authors' => [$this->makeAuthor(['media_id' => $this->makeMedia()->id])]]);
        }

        $this->assertSame($one, $count(), 'a 10-post page must not cost more queries than a 1-post page');
    }

    /** @test */
    public function related_posts_prefer_curated_then_fall_back_by_shared_taxonomy(): void
    {
        $category = $this->makeCategory();
        $tag = $this->makeTag();
        $post = $this->makePost(BlogPostStatus::Published, [], ['categories' => [$category], 'tags' => [$tag]]);
        $curated = $this->makePost(BlogPostStatus::Published, ['title' => 'Curated']);
        $curatedDraft = $this->makePost(BlogPostStatus::Draft, ['title' => 'Curated draft']);
        $both = $this->makePost(BlogPostStatus::Published, ['title' => 'Shares both', 'published_at' => now()->subDays(3)], ['categories' => [$category], 'tags' => [$tag]]);
        $one = $this->makePost(BlogPostStatus::Published, ['title' => 'Shares one'], ['tags' => [$tag]]);
        $this->makePost(BlogPostStatus::Published, ['title' => 'Unrelated']);

        $post->relatedPosts()->attach([$curatedDraft->id => ['sort_order' => 0], $curated->id => ['sort_order' => 1]]);

        $titles = array_column($this->getJson("/api/v1/blog/posts/{$post->slug}/related?limit=3")->assertOk()->json('data'), 'title');
        $this->assertSame(['Curated', 'Shares both', 'Shares one'], $titles);

        $this->getJson("/api/v1/blog/posts/{$post->slug}/related?limit=1")->assertJsonCount(1, 'data');
    }

    /** @test */
    public function related_products_are_hydrated_live_from_catalog_and_unpublished_ones_dropped(): void
    {
        $live = $this->makeProduct('Live phone', 'published', 12_000_000);
        $draft = $this->makeProduct('Draft phone', 'draft');
        $author = $this->makeAuthor();
        $this->actingAsAdmin();

        $id = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'products' => [
                ['product_id' => $live->id, 'relation_type' => 'featured'],
                ['product_id' => $draft->id, 'relation_type' => 'comparison'],
            ],
        ]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/blog/posts/{$id}/submit-review");
        $this->postJson("/api/v1/admin/blog/posts/{$id}/approve");
        $slug = $this->postJson("/api/v1/admin/blog/posts/{$id}/publish")->json('data.slug');

        $this->getJson("/api/v1/blog/posts/{$slug}")
            ->assertJsonCount(1, 'data.related_products')
            ->assertJsonPath('data.related_products.0.id', $live->uuid)
            ->assertJsonPath('data.related_products.0.base_price', 12_000_000)
            ->assertJsonPath('data.related_products.0.relation_type', 'featured');

        // Reverse lookup lives in Blog: articles about a product.
        $this->getJson("/api/v1/blog/posts?product_id={$live->id}")->assertJsonCount(1, 'data');
        $this->assertCount(1, app(BlogManagerInterface::class)->getPublishedPostsForProduct($live->id));
    }

    /** @test */
    public function the_rss_feed_contains_published_posts_only(): void
    {
        $this->makePost(BlogPostStatus::Published, ['title' => 'Live & well']);
        $this->makePost(BlogPostStatus::Draft, ['title' => 'Draft secret']);
        $this->makePost(BlogPostStatus::Scheduled, ['title' => 'Scheduled secret', 'published_at' => now()->addDay()]);

        $response = $this->get('/api/v1/blog/feed')->assertOk();
        $this->assertStringContainsString('application/rss+xml', $response->headers->get('Content-Type'));

        $xml = $response->getContent();
        $this->assertStringContainsString('Live &amp; well', $xml);
        $this->assertStringNotContainsString('secret', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    /** @test */
    public function the_sitemap_lists_indexable_posts_and_active_taxonomy(): void
    {
        $this->makePost(BlogPostStatus::Published, ['slug' => 'indexable']);
        $this->makePost(BlogPostStatus::Published, ['slug' => 'noindex', 'robots_index' => false]);
        $this->makePost(BlogPostStatus::Draft, ['slug' => 'draft']);
        $this->makeCategory(['slug' => 'active-cat']);
        $this->makeCategory(['slug' => 'inactive-cat', 'is_active' => false]);
        $this->makeAuthor(['slug' => 'writer']);

        $slugs = array_column($this->getJson('/api/v1/blog/sitemap')->assertOk()->json('data'), 'slug');
        $this->assertContains('indexable', $slugs);
        $this->assertContains('active-cat', $slugs);
        $this->assertContains('writer', $slugs);
        $this->assertNotContains('noindex', $slugs);
        $this->assertNotContains('draft', $slugs);
        $this->assertNotContains('inactive-cat', $slugs);
    }

    /** @test */
    public function the_blog_home_aggregates_featured_latest_placements_and_categories(): void
    {
        $this->makePost(BlogPostStatus::Published, ['title' => 'Featured', 'is_featured' => true]);
        $this->makePost(BlogPostStatus::Draft, ['title' => 'Hidden featured', 'is_featured' => true]);
        $this->makeCategory();

        $this->getJson('/api/v1/blog')
            ->assertOk()
            ->assertJsonCount(1, 'data.featured')
            ->assertJsonPath('data.featured.0.title', 'Featured')
            ->assertJsonCount(1, 'data.latest')
            ->assertJsonStructure(['data' => ['placements' => ['blog.home.hero', 'blog.home.editor_picks', 'blog.home.secondary'], 'categories']]);
    }

    /** @test */
    public function public_endpoints_never_require_authentication(): void
    {
        $post = $this->makePost(BlogPostStatus::Published);
        $category = $this->makeCategory();
        $tag = $this->makeTag();
        $author = $this->makeAuthor();

        foreach ([
            '/api/v1/blog', '/api/v1/blog/posts', "/api/v1/blog/posts/{$post->slug}", "/api/v1/blog/posts/{$post->slug}/related",
            "/api/v1/blog/posts/{$post->slug}/comments", '/api/v1/blog/categories', "/api/v1/blog/categories/{$category->slug}",
            "/api/v1/blog/categories/{$category->slug}/posts", '/api/v1/blog/tags', "/api/v1/blog/tags/{$tag->slug}",
            "/api/v1/blog/tags/{$tag->slug}/posts", '/api/v1/blog/authors', "/api/v1/blog/authors/{$author->slug}",
            "/api/v1/blog/authors/{$author->slug}/posts", '/api/v1/blog/collections', '/api/v1/blog/placements/blog.home.hero',
            '/api/v1/blog/feed', '/api/v1/blog/sitemap',
        ] as $url) {
            $this->get($url, ['Accept' => 'application/json'])->assertOk();
        }
    }
}
