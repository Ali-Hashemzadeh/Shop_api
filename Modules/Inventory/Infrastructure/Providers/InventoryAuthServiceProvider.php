<?php

namespace Modules\Inventory\Infrastructure\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Inventory\Domain\Policies\InventoryPolicy;

class InventoryAuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        InventoryStock::class => InventoryPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
