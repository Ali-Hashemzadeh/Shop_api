<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Infrastructure\Http\Controllers\CategoriesController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductGalleryController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductsController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductVariantsController;

Route::middleware('api')->prefix('api/v1/catalog')->group(function () {

    // ── PUBLIC: Unauthenticated storefront read routes ────────────────────────
    Route::middleware('throttle:public')->group(function () {
        Route::get('/categories/roots', [CategoriesController::class, 'indexRoots']);
        Route::get('/categories/{id}', [CategoriesController::class, 'show']);

        Route::get('/products', [ProductsController::class, 'index']);
        Route::get('/products/slug/{slug}', [ProductsController::class, 'showBySlug']);
        // Accept any UUID-or-numeric segment so a wrong/legacy id resolves to a clean
        // "Product not found." 404 in the controller instead of a routing 404. The
        // hex+hyphen pattern still excludes reserved words like `admin`/`slug`, so this
        // route never shadows `/products/admin` or `/products/slug/{slug}`.
        Route::get('/products/{uuid}', [ProductsController::class, 'show'])->where('uuid', '[0-9a-fA-F\-]+');
        Route::get('/categories/{categoryId}/products', [ProductsController::class, 'indexByCategory']);

        Route::get('/variants/sku/{sku}', [ProductVariantsController::class, 'showBySku']);
        Route::get('/variants/{variantId}', [ProductVariantsController::class, 'show']);
    });

    // ── PROTECTED: Requires valid Sanctum token; authorization enforced by policies ──
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        // Categories
        Route::post('/categories', [CategoriesController::class, 'store']);
        Route::patch('/categories/{id}', [CategoriesController::class, 'update']);
        Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

        // Products
        Route::get('/products/admin', [ProductsController::class, 'indexAdmin']);
        // Same UUID-or-numeric constraint as the public show route: a wrong/legacy id
        // reaches the controller/action for a clean 404 rather than a routing 404/405,
        // while `admin` (non-hex) is still excluded so it never shadows `/products/admin`.
        Route::get('/products/{uuid}/admin', [ProductsController::class, 'showAdmin'])->where('uuid', '[0-9a-fA-F\-]+');
        Route::post('/products', [ProductsController::class, 'store']);
        Route::patch('/products/{uuid}', [ProductsController::class, 'update'])->where('uuid', '[0-9a-fA-F\-]+');
        Route::delete('/products/{uuid}', [ProductsController::class, 'destroy'])->where('uuid', '[0-9a-fA-F\-]+');

        // Gallery management
        Route::post('/products/{productUuid}/gallery', [ProductGalleryController::class, 'store'])->where('productUuid', '[0-9a-fA-F\-]+');
        Route::delete('/products/{productUuid}/gallery/{imageId}', [ProductGalleryController::class, 'destroy'])->where('productUuid', '[0-9a-fA-F\-]+');

        // Variants
        Route::post('/products/{productUuid}/variants', [ProductVariantsController::class, 'store'])->where('productUuid', '[0-9a-fA-F\-]+');
        Route::patch('/variants/{variantId}', [ProductVariantsController::class, 'update']);
        Route::delete('/variants/{variantId}', [ProductVariantsController::class, 'destroy']);
    });
});
