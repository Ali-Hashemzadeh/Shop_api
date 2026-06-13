<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Infrastructure\Http\Controllers\CategoriesController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductsController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductVariantsController;

Route::middleware('api')->prefix('api/v1/catalog')->group(function () {

    // ── Categories ──────────────────────────────────────────────────────────
    Route::get('/categories/roots', [CategoriesController::class, 'indexRoots']);
    Route::get('/categories/{id}', [CategoriesController::class, 'show']);
    Route::post('/categories', [CategoriesController::class, 'store']);

    // ── Products ─────────────────────────────────────────────────────────────
    Route::get('/categories/{categoryId}/products', [ProductsController::class, 'indexByCategory']);
    Route::get('/products/slug/{slug}', [ProductsController::class, 'showBySlug']);
    Route::get('/products/{id}/admin', [ProductsController::class, 'showAdmin']);
    Route::get('/products/{id}', [ProductsController::class, 'show']);
    Route::post('/products', [ProductsController::class, 'store']);

    // ── Product Variants ─────────────────────────────────────────────────────
    Route::get('/variants/sku/{sku}', [ProductVariantsController::class, 'showBySku']);
    Route::get('/variants/{variantId}', [ProductVariantsController::class, 'show']);
    Route::post('/products/{productId}/variants', [ProductVariantsController::class, 'store']);
});
