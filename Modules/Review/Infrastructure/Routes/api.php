<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Support\Facades\Route;
use Modules\Review\Infrastructure\Http\Controllers\AdminReviewController;
use Modules\Review\Infrastructure\Http\Controllers\ReviewController;

// Public read — open storefront surface, `public` limiter per CLAUDE.md §4.
Route::middleware(['api', 'throttle:public'])
    ->prefix('api/v1/reviews')
    ->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
    });

$uuidPattern = PublicCodeGenerator::routePattern(PublicCodeEntity::Review);

// Authenticated writes — `api` limiter.
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/reviews')
    ->group(function () use ($uuidPattern) {
        Route::post('/', [ReviewController::class, 'store']);
        Route::patch('/{uuid}', [ReviewController::class, 'update'])->where('uuid', $uuidPattern);
    });

// ── Admin / moderation ──────────────────────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/reviews')
    ->group(function () use ($uuidPattern) {
        Route::get('/', [AdminReviewController::class, 'index']);
        Route::patch('/{uuid}/status', [AdminReviewController::class, 'moderate'])->where('uuid', $uuidPattern);
        Route::post('/{uuid}/reply', [AdminReviewController::class, 'reply'])->where('uuid', $uuidPattern);
    });
