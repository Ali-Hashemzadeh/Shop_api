<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureRateLimiting();

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
    }

    private function configureRateLimiting(): void
    {
        // OTP endpoints — strict to prevent SMS abuse and brute-force
        RateLimiter::for('otp', fn (Request $r) => Limit::perMinute(3)->by($r->ip())
        );

        // Open storefront reads — generous enough for real apps, blocks naive floods
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(120)->by($r->ip())
        );

        // Inventory batch lookup — no auth, more expensive per call
        RateLimiter::for('inventory-batch', fn (Request $r) => Limit::perMinute(30)->by($r->ip())
        );

        // File uploads — bound by storage cost, per authenticated user
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?? $r->ip())
        );

        // General authenticated API; guest cart falls back to per-IP
        RateLimiter::for('api', fn (Request $r) => $r->user()
                ? Limit::perMinute(60)->by($r->user()->id)
                : Limit::perMinute(30)->by($r->ip())
        );
    }
}
