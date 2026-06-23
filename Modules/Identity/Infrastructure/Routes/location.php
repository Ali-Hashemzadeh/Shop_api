<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\LocationController;

Route::middleware('throttle:public')->prefix('locations')->group(function () {
    Route::get('/provinces', [LocationController::class, 'provinces']);
    Route::get('/provinces/{province}', [LocationController::class, 'show']);
    Route::get('/provinces/{province}/cities', [LocationController::class, 'cities']);
});
