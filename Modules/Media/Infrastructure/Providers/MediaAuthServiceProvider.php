<?php

namespace Modules\Media\Infrastructure\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Modules\Media\Domain\Models\Media;
use Modules\Media\Domain\Policies\MediaPolicy;

class MediaAuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Media::class => MediaPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
