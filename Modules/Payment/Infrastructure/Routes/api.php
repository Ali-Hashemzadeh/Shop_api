<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Infrastructure\Http\Controllers\PaymentController;

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/payments')
    ->group(function () {
        Route::post('/initialize', [PaymentController::class, 'initialize']);
    });

Route::middleware(['api', 'throttle:public'])
    ->prefix('api/v1/payments')
    ->group(function () {
        Route::get('/zarinpal/callback', [PaymentController::class, 'zarinpalCallback']);
    });
