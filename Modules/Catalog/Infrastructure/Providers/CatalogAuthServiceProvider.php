<?php

namespace Modules\Catalog\Infrastructure\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Modules\Catalog\Domain\Models\Brand;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Catalog\Domain\Policies\BrandPolicy;
use Modules\Catalog\Domain\Policies\CategoryPolicy;
use Modules\Catalog\Domain\Policies\ProductPolicy;
use Modules\Catalog\Domain\Policies\ProductVariantPolicy;

class CatalogAuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Category::class => CategoryPolicy::class,
        Brand::class => BrandPolicy::class,
        Product::class => ProductPolicy::class,
        ProductVariant::class => ProductVariantPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
