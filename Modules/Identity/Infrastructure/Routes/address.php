<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\AddressController;

Route::get('/addresses', [AddressController::class, 'index']);
Route::post('/addresses', [AddressController::class, 'store']);
Route::get('/addresses/{address}', [AddressController::class, 'show']);
Route::patch('/addresses/{address}', [AddressController::class, 'update']);
Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);
Route::post('/addresses/{address}/default-shipping', [AddressController::class, 'setDefaultShipping']);
