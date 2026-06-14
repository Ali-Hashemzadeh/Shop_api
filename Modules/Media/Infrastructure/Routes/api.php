<?php

use Illuminate\Support\Facades\Route;
use Modules\Media\Infrastructure\Http\Controllers\MediaController;

Route::middleware('api')->prefix('api/v1/media')->group(function () {

    Route::get('/health', function () {
        return response()->json(['status' => 'Media module is functional']);
    });

    // ── PROTECTED: Requires valid Sanctum token; authorization enforced by policy ──
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/', [MediaController::class, 'store']);
        Route::delete('/{id}', [MediaController::class, 'destroy']);
    });
});
