<?php

use Illuminate\Support\Facades\Route;

// Private or Public routes for the Media module
Route::prefix('v1/media')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => 'Media module is functional']);
    });
});