<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    require __DIR__.'/authentication.php';
    require __DIR__.'/location.php';

    Route::middleware('auth:sanctum')->group(function () {
        require __DIR__.'/profile.php';
        require __DIR__.'/address.php';
    });
});
