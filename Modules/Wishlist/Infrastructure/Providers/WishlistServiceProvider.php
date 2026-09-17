<?php

declare(strict_types=1);

namespace Modules\Wishlist\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;
use Modules\Wishlist\Infrastructure\Persistence\Repositories\EloquentWishlistManager;

/**
 * Wishlist is a leaf module: it binds its contract and loads its migrations,
 * but owns no routes (Catalog exposes the customer-facing endpoints and calls
 * WishlistManagerInterface) and seeds no permissions (likes and availability
 * requests are self-service, like the cart).
 */
class WishlistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WishlistManagerInterface::class, EloquentWishlistManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
