<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\AuthController;

Route::middleware('throttle:otp')->group(function () {
    Route::post('/otp/request', [AuthController::class, 'requestOtp']);
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
    // Password login shares the strict OTP limiter to resist brute force.
    Route::post('/auth/login-password', [AuthController::class, 'loginPassword']);
});

// Account lookup is a low-risk public read; the broad public limiter applies.
Route::middleware('throttle:public')->group(function () {
    Route::post('/auth/check-user', [AuthController::class, 'checkUser']);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // Authenticated users add or replace their password; the token proves ownership.
    Route::post('/auth/set-password', [AuthController::class, 'setPassword']);
});
