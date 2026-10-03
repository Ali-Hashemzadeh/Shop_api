<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Support\Facades\Route;
use Modules\ProductReviewAI\Infrastructure\Http\Controllers\AiPromptController;
use Modules\ProductReviewAI\Infrastructure\Http\Controllers\AiReviewController;
use Modules\ProductReviewAI\Infrastructure\Http\Controllers\ProductAiReviewController;

// Product handle: the same public-code pattern Catalog uses (new bdp-XXXXXX and
// legacy 7-char hex), so /ai-reviews resolves exactly what /catalog/products does.
$productPattern = PublicCodeGenerator::routePattern(PublicCodeEntity::Product, '[0-9a-fA-F\-]+');

// ── Product-scoped admin workflow ────────────────────────────────────────────
// Admin only (product-review-ai.manage, enforced in the Form Requests). The
// authenticated `api` limiter applies; the queue job — not the request — does
// the scraping and AI calls.
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/products/{product}/ai-reviews')
    ->where(['product' => $productPattern])
    ->group(function () {
        Route::get('search', [ProductAiReviewController::class, 'search']);
        Route::get('mappings', [ProductAiReviewController::class, 'mappings']);
        Route::post('mappings', [ProductAiReviewController::class, 'storeMapping']);
        Route::post('generate', [ProductAiReviewController::class, 'generate']);
        Route::post('regenerate', [ProductAiReviewController::class, 'regenerate']);
        Route::get('generations', [ProductAiReviewController::class, 'generations']);
    });

// ── Generation detail + draft moderation ─────────────────────────────────────
// GET {generation} = a run + its drafts; PATCH/approve/reject {draft} = one draft.
// Shared prefix, distinct verbs — no collision.
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/ai-reviews')
    ->group(function () {
        Route::get('{generation}', [AiReviewController::class, 'show'])->whereNumber('generation');
        Route::patch('{draft}', [AiReviewController::class, 'update'])->whereNumber('draft');
        Route::post('{draft}/approve', [AiReviewController::class, 'approve'])->whereNumber('draft');
        Route::post('{draft}/reject', [AiReviewController::class, 'reject'])->whereNumber('draft');
    });

// ── Prompt templates (read-only) ─────────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin/ai-prompts')
    ->group(function () {
        Route::get('/', [AiPromptController::class, 'index']);
        Route::get('{prompt}', [AiPromptController::class, 'show'])->whereNumber('prompt');
    });
