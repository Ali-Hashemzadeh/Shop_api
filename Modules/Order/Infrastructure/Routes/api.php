<?php

use Illuminate\Support\Facades\Route;
use Modules\Order\Infrastructure\Http\Controllers\AdminOrderController;
use Modules\Order\Infrastructure\Http\Controllers\OrderController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/orders')
    ->group(function () {
        Route::post('/', [OrderController::class, 'store']);
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order');
    });

// ── Admin / operator: view, search, cancel only (no status/create/edit) ──────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/orders')
    ->group(function () {
        Route::get('/', [AdminOrderController::class, 'index']);
        Route::get('/{order}', [AdminOrderController::class, 'show'])->whereNumber('order');
        Route::post('/{order}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('order');
    });
