<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\ProfileController;

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'showMe']);
        Route::patch('/', [ProfileController::class, 'updateMe']);

        Route::get('/me', [ProfileController::class, 'showMe']);
        Route::patch('/me', [ProfileController::class, 'updateMe']);
        Route::get('/myAddresses', [ProfileController::class, 'myAddresses']);
        Route::get('/show/{user}', [ProfileController::class, 'show']);
        Route::get('/{user}/addresses', [ProfileController::class, 'addresses']);
        Route::patch('/{user}', [ProfileController::class, 'update']);
        Route::delete('/{user}', [ProfileController::class, 'destroy']);
    });
});
