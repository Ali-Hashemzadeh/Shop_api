<?php

use Illuminate\Support\Facades\Route;
use Modules\Order\Infrastructure\Http\Controllers\OrderController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/orders')
    ->group(function () {
        Route::post('/', [OrderController::class, 'store']);
        Route::get('/', [OrderController::class, 'index']);
    });
