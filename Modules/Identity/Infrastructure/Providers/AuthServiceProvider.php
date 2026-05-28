<?php

namespace Modules\Identity\Infrastructure\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Policies\AddressPolicy;
use Modules\Identity\Domain\Policies\ProfilePolicy;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        User::class => ProfilePolicy::class,
        Address::class => AddressPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
