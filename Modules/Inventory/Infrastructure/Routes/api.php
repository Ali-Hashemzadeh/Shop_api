<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Infrastructure\Http\Controllers\InventoryController;

Route::middleware('api')->prefix('api/v1/inventory')->group(function () {

    // ── PUBLIC: Storefront stock lookups ─────────────────────────────────────
    Route::get('/sku/{sku}', [InventoryController::class, 'showBySku'])->middleware('throttle:public');
    Route::post('/batch', [InventoryController::class, 'batchShow'])->middleware('throttle:inventory-batch');

    // ── ADMIN: Stock management and audit log ────────────────────────────────
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/adjust', [InventoryController::class, 'adjust']);
        Route::get('/sku/{sku}/ledger', [InventoryController::class, 'ledger']);
    });
});
