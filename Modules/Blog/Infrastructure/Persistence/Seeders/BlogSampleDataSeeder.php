<?php

namespace Modules\Blog\Infrastructure\Persistence\Seeders;

use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Modules\Blog\Application\Actions\ApproveBlogPostAction;
use Modules\Blog\Application\Actions\CreateBlogPostAction;
use Modules\Blog\Application\Actions\PublishBlogPostAction;
use Modules\Blog\Application\Actions\SaveBlogCollectionAction;
use Modules\Blog\Application\Actions\ScheduleBlogPostAction;
use Modules\Blog\Application\Actions\SubmitBlogPostForReviewAction;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogCollection;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogPlacement;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;

/**
 * Persian demo content for the Blog: a category tree, tags, an editorial
 * author, published / scheduled / draft articles (one shoppable), a collection,
 * homepage placements, and an approved discussion.
 *
 * Idempotent: every row is keyed by slug and skipped when present, so a reseed
 * never duplicates content or overwrites editor changes. Articles go through
 * the real Actions (create → submit → approve → publish/schedule), so public
 * codes, revisions, audit entries, and counters are exactly what production
 * writes — the seeder runs under WithoutModelEvents, which is why codes come
 * from createWithPublicCode inside the Actions rather than the model hook.
 * Cross-module ids (the admin account, demo products) are resolved through
 * the Identity and Catalog contracts, never hard-coded.
 */
class BlogSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $actorId = app(IdentityManagerInterface::class)->getAdminUserIds()[0] ?? null;

        if ($actorId === null) {
            $this->command?->warn('BlogSampleDataSeeder: no admin account found — skipping Blog demo content.');

            return;
        }

        $guides = $this->category('راهنمای خرید', 'buying-guides', null, 1);
        $phones = $this->category('موبایل', 'mobile-guides', $guides->id, 1);
        $laptops = $this->category('لپ‌تاپ', 'laptop-guides', $guides->id, 2);
        $news = $this->category('اخبار فروشگاه', 'store-news', null, 2);

        $tagPhone = $this->tag('گوشی', 'phone');
        $tagLaptop = $this->tag('لپ‌تاپ', 'laptop');
        $tagTips = $this->tag('نکات خرید', 'buying-tips');

        $author = BlogAuthor::query()->firstOrCreate(
            ['slug' => 'editorial-team'],
            [
                'display_name' => 'تیم تحریریه',
                'user_id' => BlogAuthor::query()->where('user_id', $actorId)->exists() ? null : $actorId,
                'bio' => 'راهنماها و بررسی‌های تخصصی فروشگاه.',
                'job_title' => 'تحریریه',
                'is_active' => true,
            ],
        );

        $productIds = collect(app(CatalogManagerInterface::class)->getProducts([], 3)->items())
            ->map(fn ($product) => $product->id)
            ->values()
            ->all();

        $phoneGuide = $this->article($actorId, 'how-to-choose-a-phone', [
            'title' => 'راهنمای خرید گوشی هوشمند در سال ۱۴۰۴',
            'excerpt' => 'پیش از خرید گوشی به چه نکاتی توجه کنیم؟',
            'content' => '<h2>چرا این راهنما؟</h2><p>انتخاب گوشی با این همه گزینه ساده نیست. در این مقاله مهم‌ترین معیارها را مرور می‌کنیم.</p>'
                .'<h3>باتری</h3><p>ظرفیت باتری و پشتیبانی از شارژ سریع را بررسی کنید.</p>'
                .'<ul><li>نمایشگر</li><li>دوربین</li><li>پردازنده</li></ul>',
            'category_ids' => [$phones->id, $guides->id],
            'primary_category_id' => $phones->id,
            'tag_ids' => [$tagPhone->id, $tagTips->id],
            'authors' => [['author_id' => $author->id, 'role' => 'author']],
            'products' => array_map(fn (int $id, int $i) => ['product_id' => $id, 'relation_type' => $i === 0 ? 'featured' : 'recommended'], $productIds, array_keys($productIds)),
            'is_featured' => true,
        ], publish: true);

        $laptopGuide = $this->article($actorId, 'student-laptop-guide', [
            'title' => 'بهترین لپ‌تاپ برای دانشجویان',
            'excerpt' => 'لپ‌تاپ مناسب درس و کار با بودجه محدود.',
            'content' => '<h2>معیارهای انتخاب</h2><p>وزن، عمر باتری و کیفیت صفحه‌کلید برای دانشجو اهمیت زیادی دارد.</p>',
            'category_ids' => [$laptops->id],
            'tag_ids' => [$tagLaptop->id, $tagTips->id],
            'authors' => [['author_id' => $author->id, 'role' => 'author']],
        ], publish: true);

        $this->article($actorId, 'nowruz-delivery-schedule', [
            'title' => 'برنامه ارسال سفارش‌ها در تعطیلات نوروز',
            'excerpt' => 'زمان‌بندی ارسال سفارش‌ها در ایام نوروز.',
            'content' => '<p>ارسال سفارش‌ها در تعطیلات نوروز طبق برنامه زیر انجام می‌شود.</p>',
            'category_ids' => [$news->id],
            'authors' => [['author_id' => $author->id, 'role' => 'author']],
        ], schedule: now()->addDays(3));

        $this->article($actorId, 'headphones-guide-draft', [
            'title' => 'راهنمای خرید هدفون (پیش‌نویس)',
            'content' => '<p>در حال نگارش…</p>',
            'authors' => [['author_id' => $author->id, 'role' => 'author']],
        ]);

        if (! BlogCollection::query()->where('slug', 'editors-picks')->exists()) {
            app(SaveBlogCollectionAction::class)->handle(null, [
                'name' => 'انتخاب سردبیر',
                'slug' => 'editors-picks',
                'description' => 'مقاله‌هایی که پیشنهاد می‌کنیم حتما بخوانید.',
                'is_active' => true,
                'post_ids' => [$phoneGuide->id, $laptopGuide->id],
            ]);
        }

        BlogPlacement::query()->firstOrCreate(['placement_key' => 'blog.home.hero', 'blog_post_id' => $phoneGuide->id], ['sort_order' => 0]);
        BlogPlacement::query()->firstOrCreate(['placement_key' => 'blog.home.editor_picks', 'blog_post_id' => $laptopGuide->id], ['sort_order' => 0]);

        if (! BlogComment::query()->where('blog_post_id', $phoneGuide->id)->exists()) {
            BlogComment::createWithPublicCode([
                'blog_post_id' => $phoneGuide->id,
                'user_id' => $actorId,
                'body' => 'ممنون از راهنمای کامل! بخش باتری خیلی کمک کرد.',
                'status' => BlogCommentStatus::Approved->value,
                'approved_at' => now(),
                'approved_by_user_id' => $actorId,
            ]);
            app(BlogPostStats::class)->recountComments((int) $phoneGuide->id);
        }
    }

    private function category(string $name, string $slug, ?int $parentId, int $sort): BlogCategory
    {
        return BlogCategory::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'parent_id' => $parentId, 'is_active' => true, 'sort_order' => $sort],
        );
    }

    private function tag(string $name, string $slug): BlogTag
    {
        return BlogTag::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
    }

    private function article(int $actorId, string $slug, array $data, bool $publish = false, ?CarbonInterface $schedule = null): BlogPost
    {
        $existing = BlogPost::withTrashed()->where('slug', $slug)->first();
        if ($existing !== null) {
            return $existing;
        }

        $post = app(CreateBlogPostAction::class)->handle([...$data, 'slug' => $slug], $actorId);

        if ($publish || $schedule !== null) {
            $post = app(SubmitBlogPostForReviewAction::class)->handle($post, $actorId);
            $post = app(ApproveBlogPostAction::class)->handle($post, $actorId);
        }

        if ($schedule !== null) {
            return app(ScheduleBlogPostAction::class)->handle($post, $schedule, $actorId);
        }

        return $publish ? app(PublishBlogPostAction::class)->handle($post, $actorId) : $post;
    }
}
