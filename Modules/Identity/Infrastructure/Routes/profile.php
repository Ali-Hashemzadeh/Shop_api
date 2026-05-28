<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\ProfileController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [ProfileController::class, 'index']);

    Route::get('/me', [ProfileController::class, 'showMe']);
    Route::POST('/me', [ProfileController::class, 'updateMe']);

    Route::get('/{user}', [ProfileController::class, 'show']);
    Route::get('/{user}/addresses', [ProfileController::class, 'addresses']);
    Route::POST('/{user}', [ProfileController::class, 'update']);
    Route::delete('/{user}', [ProfileController::class, 'destroy']);
});
