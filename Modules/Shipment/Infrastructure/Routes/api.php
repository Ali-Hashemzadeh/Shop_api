<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Infrastructure\Http\Controllers\AdminDeliverySlotController;
use Modules\Shipment\Infrastructure\Http\Controllers\AdminDeliveryWorkingPeriodController;
use Modules\Shipment\Infrastructure\Http\Controllers\AdminShipmentController;
use Modules\Shipment\Infrastructure\Http\Controllers\ShipmentController;
use Modules\Shipment\Infrastructure\Http\Controllers\ShipmentMethodController;

// ── Customer-facing ────────────────────────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1')
    ->group(function () {
        Route::get('shipment/methods', [ShipmentMethodController::class, 'index']);
        Route::get('shipment/delivery-slots', [ShipmentMethodController::class, 'deliverySlots']);

        Route::get('shipments/{publicCode}', [ShipmentController::class, 'show'])
            ->where('publicCode', '[A-Za-z0-9\-]+');
        Route::get('orders/{order}/shipment', [ShipmentController::class, 'showForOrder'])
            ->whereNumber('order');
    });

// ── Admin / operator ────────────────────────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin')
    ->group(function () {
        Route::get('shipments', [AdminShipmentController::class, 'index']);
        Route::get('shipments/{publicCode}', [AdminShipmentController::class, 'show'])->where('publicCode', '[A-Za-z0-9\-]+');

        Route::prefix('shipments/{publicCode}')->where(['publicCode' => '[A-Za-z0-9\-]+'])->group(function () {
            Route::post('start-preparing', [AdminShipmentController::class, 'startPreparing']);
            Route::post('mark-ready-for-post', [AdminShipmentController::class, 'markReadyForPost']);
            Route::post('hand-to-post', [AdminShipmentController::class, 'handToPost']);
            Route::post('mark-ready-for-dispatch', [AdminShipmentController::class, 'markReadyForDispatch']);
            Route::post('mark-out-for-delivery', [AdminShipmentController::class, 'markOutForDelivery']);
            Route::post('mark-delivered', [AdminShipmentController::class, 'markDelivered']);
            Route::post('mark-delivery-failed', [AdminShipmentController::class, 'markDeliveryFailed']);
            Route::post('reschedule', [AdminShipmentController::class, 'reschedule']);
            Route::post('mark-ready-for-pickup', [AdminShipmentController::class, 'markReadyForPickup']);
            Route::post('confirm-pickup', [AdminShipmentController::class, 'confirmPickup']);
        });

        Route::get('shipment/delivery-slots', [AdminDeliverySlotController::class, 'index']);
        Route::patch('shipment/delivery-slots/{slot}', [AdminDeliverySlotController::class, 'update'])->whereNumber('slot');
        Route::post('shipment/delivery-slots/{slot}/close', [AdminDeliverySlotController::class, 'close'])->whereNumber('slot');
        Route::post('shipment/delivery-slots/{slot}/open', [AdminDeliverySlotController::class, 'open'])->whereNumber('slot');

        Route::get('shipment/delivery-working-periods', [AdminDeliveryWorkingPeriodController::class, 'index']);
        Route::post('shipment/delivery-working-periods', [AdminDeliveryWorkingPeriodController::class, 'store']);
        Route::patch('shipment/delivery-working-periods/{workingPeriod}', [AdminDeliveryWorkingPeriodController::class, 'update'])->whereNumber('workingPeriod');
        Route::delete('shipment/delivery-working-periods/{workingPeriod}', [AdminDeliveryWorkingPeriodController::class, 'destroy'])->whereNumber('workingPeriod');
    });
