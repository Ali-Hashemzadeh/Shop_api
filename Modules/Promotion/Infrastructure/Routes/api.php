<?php

use Illuminate\Support\Facades\Route;
use Modules\Promotion\Infrastructure\Http\Controllers\AdminCampaignController;
use Modules\Promotion\Infrastructure\Http\Controllers\AdminCouponController;
use Modules\Promotion\Infrastructure\Http\Controllers\AdminDiscountController;
use Modules\Promotion\Infrastructure\Http\Controllers\AdminRedemptionController;

/*
 * Promotion exposes NO customer-facing routes.
 *
 * Everything a shopper sees is served by the module that owns the surface:
 * live pricing and campaign product lists come from Catalog, and the coupon
 * preview hangs off the order it applies to (Order). That keeps Promotion a
 * pure pricing authority with no storefront of its own.
 *
 * Every route below is admin-only. Authorization is enforced per-request by the
 * Form Requests (403 before validation), with the destroy routes checking
 * explicitly since they take no body.
 */
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/promotions')
    ->group(function () {
        // ── Discounts: promotion.view-admin / create / update / delete ────────
        Route::get('/discounts', [AdminDiscountController::class, 'index']);
        Route::get('/discounts/{discount}', [AdminDiscountController::class, 'show'])->whereNumber('discount');
        Route::post('/discounts', [AdminDiscountController::class, 'store']);
        Route::patch('/discounts/{discount}', [AdminDiscountController::class, 'update'])->whereNumber('discount');
        Route::put('/discounts/{discount}', [AdminDiscountController::class, 'update'])->whereNumber('discount');
        Route::delete('/discounts/{discount}', [AdminDiscountController::class, 'destroy'])->whereNumber('discount');

        // ── Coupons: reads promotion.view-admin, writes promotion.coupon.manage ──
        Route::get('/coupons', [AdminCouponController::class, 'index']);
        Route::get('/coupons/{coupon}', [AdminCouponController::class, 'show'])->whereNumber('coupon');
        Route::post('/coupons', [AdminCouponController::class, 'store']);
        Route::patch('/coupons/{coupon}', [AdminCouponController::class, 'update'])->whereNumber('coupon');
        Route::put('/coupons/{coupon}', [AdminCouponController::class, 'update'])->whereNumber('coupon');
        Route::delete('/coupons/{coupon}', [AdminCouponController::class, 'destroy'])->whereNumber('coupon');

        // ── Campaigns: reads promotion.view-admin, writes promotion.campaign.manage ──
        Route::get('/campaigns', [AdminCampaignController::class, 'index']);
        Route::get('/campaigns/{campaign}', [AdminCampaignController::class, 'show'])->whereNumber('campaign');
        Route::post('/campaigns', [AdminCampaignController::class, 'store']);
        Route::patch('/campaigns/{campaign}', [AdminCampaignController::class, 'update'])->whereNumber('campaign');
        Route::put('/campaigns/{campaign}', [AdminCampaignController::class, 'update'])->whereNumber('campaign');
        Route::delete('/campaigns/{campaign}', [AdminCampaignController::class, 'destroy'])->whereNumber('campaign');

        // ── Redemption history: read-only, promotion.view-admin ───────────────
        Route::get('/redemptions', [AdminRedemptionController::class, 'index']);
    });
