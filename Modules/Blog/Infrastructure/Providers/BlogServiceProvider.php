<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Providers;

use App\Console\Commands\BlogsPublishScheduledCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Blog\Domain\Contracts\BlogHtmlSanitizerInterface;
use Modules\Blog\Domain\Contracts\BlogManagerInterface;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Policies\BlogCommentPolicy;
use Modules\Blog\Domain\Policies\BlogPostPolicy;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogCategoryTree;
use Modules\Blog\Infrastructure\Persistence\Repositories\EloquentBlogManager;
use Modules\Blog\Infrastructure\Sanitization\SymfonyBlogHtmlSanitizer;

/**
 * The Blog/CMS bounded context.
 *
 * Depends on the Media, Catalog, and Identity contracts only. Publishes
 * BlogManagerInterface and primitives-only integration events in
 * Domain/Events; Notification and Analytics listen to those events (listeners
 * live in their modules), so Blog never calls either of them.
 */
class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BlogManagerInterface::class, EloquentBlogManager::class);
        $this->app->singleton(BlogHtmlSanitizerInterface::class, SymfonyBlogHtmlSanitizer::class);

        // One in-memory category map per request/job.
        $this->app->scoped(BlogCategoryTree::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        Gate::policy(BlogPost::class, BlogPostPolicy::class);
        Gate::policy(BlogComment::class, BlogCommentPolicy::class);

        $this->commands([
            BlogsPublishScheduledCommand::class,
        ]);
    }
}
