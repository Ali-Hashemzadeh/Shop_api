<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Infrastructure\Http\Controllers\CategoriesController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductGalleryController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductsController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductVariantsController;

Route::middleware('api')->prefix('api/v1/catalog')->group(function () {

    // ── PUBLIC: Unauthenticated storefront read routes ────────────────────────
    Route::get('/categories/roots', [CategoriesController::class, 'indexRoots']);
    Route::get('/categories/{id}', [CategoriesController::class, 'show']);

    Route::get('/products/slug/{slug}', [ProductsController::class, 'showBySlug']);
    Route::get('/products/{id}', [ProductsController::class, 'show']);
    Route::get('/categories/{categoryId}/products', [ProductsController::class, 'indexByCategory']);

    Route::get('/variants/sku/{sku}', [ProductVariantsController::class, 'showBySku']);
    Route::get('/variants/{variantId}', [ProductVariantsController::class, 'show']);

    // ── PROTECTED: Requires valid Sanctum token; authorization enforced by policies ──
    Route::middleware('auth:sanctum')->group(function () {

        // Categories
        Route::post('/categories', [CategoriesController::class, 'store']);
        Route::patch('/categories/{id}', [CategoriesController::class, 'update']);
        Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

        // Products
        Route::get('/products/{id}/admin', [ProductsController::class, 'showAdmin']);
        Route::post('/products', [ProductsController::class, 'store']);
        Route::patch('/products/{id}', [ProductsController::class, 'update']);
        Route::delete('/products/{id}', [ProductsController::class, 'destroy']);

        // Gallery management
        Route::post('/products/{productId}/gallery', [ProductGalleryController::class, 'store']);
        Route::delete('/products/{productId}/gallery/{imageId}', [ProductGalleryController::class, 'destroy']);

        // Variants
        Route::post('/products/{productId}/variants', [ProductVariantsController::class, 'store']);
        Route::patch('/variants/{variantId}', [ProductVariantsController::class, 'update']);
        Route::delete('/variants/{variantId}', [ProductVariantsController::class, 'destroy']);
    });
});
