<?php

namespace Modules\Review\Infrastructure\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Review\Domain\Contracts\ReviewManagerInterface;
use Modules\Review\Domain\Models\Review;
use Modules\Review\Domain\Policies\ReviewPolicy;
use Modules\Review\Infrastructure\Persistence\Repositories\EloquentReviewManager;

class ReviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReviewManagerInterface::class, EloquentReviewManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        Gate::policy(Review::class, ReviewPolicy::class);
    }
}
