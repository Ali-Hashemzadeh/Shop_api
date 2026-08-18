<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Infrastructure\Http\Controllers\AdminAnalyticsController;

/*
 * Analytics exposes admin reporting routes.
 *
 * All routes are guarded by Sanctum authentication and require the `analytics.view`
 * permission (enforced in Form Requests for 403-before-validation).
 */
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/analytics')
    ->group(function () {
        Route::get('/dashboard', [AdminAnalyticsController::class, 'dashboard']);
        Route::get('/sales', [AdminAnalyticsController::class, 'sales']);
        Route::get('/products', [AdminAnalyticsController::class, 'products']);
        Route::get('/customers', [AdminAnalyticsController::class, 'customers']);
        Route::get('/delivery', [AdminAnalyticsController::class, 'delivery']);
    });
