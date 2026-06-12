<?php

use Illuminate\Support\Facades\Route;

// Private or Public routes for the Catalog module
Route::prefix('v1/catalog')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => 'Catalog module is functional']);
    });
});