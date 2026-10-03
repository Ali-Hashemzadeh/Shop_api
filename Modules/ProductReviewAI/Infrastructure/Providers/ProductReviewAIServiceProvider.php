<?php

namespace Modules\ProductReviewAI\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ProductReviewAI\Domain\Contracts\ProductReviewAIManagerInterface;
use Modules\ProductReviewAI\Infrastructure\Persistence\Repositories\EloquentProductReviewAIManager;
use Modules\ProductReviewAI\Infrastructure\Support\AIProviderFactory;
use Modules\ProductReviewAI\Infrastructure\Support\ReviewSourceFactory;

class ProductReviewAIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/product_review_ai.php', 'product_review_ai');

        $this->app->bind(ProductReviewAIManagerInterface::class, EloquentProductReviewAIManager::class);

        $this->app->singleton(ReviewSourceFactory::class);
        $this->app->singleton(AIProviderFactory::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
