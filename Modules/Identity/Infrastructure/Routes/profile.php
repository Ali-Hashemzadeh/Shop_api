<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\AdminAddressController;
use Modules\Identity\Infrastructure\Http\Controllers\AdminUserController;
use Modules\Identity\Infrastructure\Http\Controllers\ProfileController;

Route::prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'showMe']);
    Route::patch('/', [ProfileController::class, 'updateMe']);
    Route::get('/myAddresses', [ProfileController::class, 'myAddresses']);
});

Route::prefix('admin')->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::get('/show/{user}', [AdminUserController::class, 'show']);
        Route::get('/{user}/addresses', [AdminAddressController::class, 'indexForUser']);
        Route::patch('/{user}', [AdminUserController::class, 'update']);
        Route::delete('/{user}', [AdminUserController::class, 'destroy']);
    });
});
