<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Infrastructure\Http\Controllers\BrandsController;
use Modules\Catalog\Infrastructure\Http\Controllers\CampaignsController;
use Modules\Catalog\Infrastructure\Http\Controllers\CategoriesController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductGalleryController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductsController;
use Modules\Catalog\Infrastructure\Http\Controllers\ProductVariantsController;

/*
 * Product-level routes accept two identifier shapes:
 *   - the new public code, `bdp-XXXXXX`
 *   - legacy 7-char hex codes issued before the public-code scheme, so existing
 *     URLs and bookmarks keep resolving
 *
 * Neither alternative can match a reserved literal segment (`admin`, `slug`):
 * the legacy branch is hex-only and the new branch requires the `bdp-` prefix,
 * so `/products/admin` and `/products/slug/{slug}` are never shadowed. Anything
 * else 404s in the controller with "Product not found." rather than at routing.
 *
 * Note: this pattern embeds the configured namespace, so `route:clear` is
 * required after changing PUBLIC_CODE_NAMESPACE on a route-cached deployment.
 */
$productCode = PublicCodeGenerator::routePattern(PublicCodeEntity::Product, '[0-9a-fA-F\-]+');

Route::middleware('api')->prefix('api/v1/catalog')->group(function () use ($productCode) {

    // ── PUBLIC: Unauthenticated storefront read routes ────────────────────────
    Route::middleware('throttle:public')->group(function () use ($productCode) {
        Route::get('/categories/roots', [CategoriesController::class, 'indexRoots']);
        Route::get('/categories/{id}', [CategoriesController::class, 'show']);

        Route::get('/brands', [BrandsController::class, 'index']);
        Route::get('/brands/{id}', [BrandsController::class, 'show']);

        // Campaign merchandising. Served by Catalog (not Promotion) because
        // resolving a campaign's products needs Catalog's own tables — see
        // CampaignsController.
        Route::get('/campaigns', [CampaignsController::class, 'index']);
        Route::get('/campaigns/{slug}/products', [CampaignsController::class, 'products']);
        Route::get('/campaigns/{slug}', [CampaignsController::class, 'show']);

        Route::get('/products', [ProductsController::class, 'index']);
        Route::get('/products/slug/{slug}', [ProductsController::class, 'showBySlug']);
        Route::get('/products/{uuid}', [ProductsController::class, 'show'])->where('uuid', $productCode);
        Route::get('/categories/{categoryId}/products', [ProductsController::class, 'indexByCategory']);

        Route::get('/variants/sku/{sku}', [ProductVariantsController::class, 'showBySku']);
        Route::get('/variants/{variantId}', [ProductVariantsController::class, 'show']);
    });

    // ── PROTECTED: Requires valid Sanctum token; authorization enforced by policies ──
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () use ($productCode) {

        // Categories
        Route::post('/categories', [CategoriesController::class, 'store']);
        Route::patch('/categories/{id}', [CategoriesController::class, 'update']);
        Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

        // Brands
        Route::post('/brands', [BrandsController::class, 'store']);
        Route::patch('/brands/{id}', [BrandsController::class, 'update']);
        Route::delete('/brands/{id}', [BrandsController::class, 'destroy']);

        // Products
        Route::get('/products/admin', [ProductsController::class, 'indexAdmin']);
        // Same two-format constraint as the public show route, so `/products/admin`
        // is never shadowed and a wrong id reaches the controller for a clean 404.
        Route::get('/products/{uuid}/admin', [ProductsController::class, 'showAdmin'])->where('uuid', $productCode);
        Route::post('/products', [ProductsController::class, 'store']);
        Route::patch('/products/{uuid}', [ProductsController::class, 'update'])->where('uuid', $productCode);
        Route::delete('/products/{uuid}', [ProductsController::class, 'destroy'])->where('uuid', $productCode);

        // Gallery management
        Route::post('/products/{productUuid}/gallery', [ProductGalleryController::class, 'store'])->where('productUuid', $productCode);
        Route::delete('/products/{productUuid}/gallery/{imageId}', [ProductGalleryController::class, 'destroy'])->where('productUuid', $productCode);

        // Variants
        Route::post('/products/{productUuid}/variants', [ProductVariantsController::class, 'store'])->where('productUuid', $productCode);
        Route::patch('/variants/{variantId}', [ProductVariantsController::class, 'update']);
        Route::delete('/variants/{variantId}', [ProductVariantsController::class, 'destroy']);
    });
});
