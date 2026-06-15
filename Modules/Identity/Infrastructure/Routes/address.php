<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\AddressController;
use Modules\Identity\Infrastructure\Http\Controllers\AdminAddressController;

Route::prefix('addresses')->group(function () {
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::get('/{address}', [AddressController::class, 'show']);
    Route::patch('/{address}', [AddressController::class, 'update']);
    Route::delete('/{address}', [AddressController::class, 'destroy']);
    Route::post('/{address}/default-shipping', [AddressController::class, 'setDefaultShipping']);
});

Route::prefix('admin')->group(function () {
    Route::prefix('address')->group(function () {
        Route::get('/{address}', [AdminAddressController::class, 'show']);
        Route::post('/{user}', [AdminAddressController::class, 'store']);
        Route::patch('/{address}', [AdminAddressController::class, 'update']);
        Route::delete('/{address}', [AdminAddressController::class, 'destroy']);
    });
});

