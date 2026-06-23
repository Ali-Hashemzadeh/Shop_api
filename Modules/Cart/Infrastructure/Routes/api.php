<?php

use Illuminate\Support\Facades\Route;
use Modules\Cart\Infrastructure\Http\Controllers\CartController;

Route::middleware(['api', 'cart.identify', 'throttle:api'])->prefix('api/v1/cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'addItem']);
    Route::patch('/items/{itemId}', [CartController::class, 'updateItem']);
    Route::delete('/items/{itemId}', [CartController::class, 'removeItem']);
    Route::delete('/', [CartController::class, 'clear']);
});

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])->prefix('api/v1/cart')->group(function () {
    Route::post('/merge', [CartController::class, 'merge']);
});
