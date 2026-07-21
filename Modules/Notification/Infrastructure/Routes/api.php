<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Infrastructure\Http\Controllers\NotificationController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/notifications')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->whereNumber('notification');
    });
