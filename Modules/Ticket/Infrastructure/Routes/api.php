<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Support\Facades\Route;
use Modules\Ticket\Infrastructure\Http\Controllers\AdminSupportUserController;
use Modules\Ticket\Infrastructure\Http\Controllers\AdminTicketCategoryController;
use Modules\Ticket\Infrastructure\Http\Controllers\AdminTicketController;
use Modules\Ticket\Infrastructure\Http\Controllers\SupportTicketController;
use Modules\Ticket\Infrastructure\Http\Controllers\TicketCategoryController;
use Modules\Ticket\Infrastructure\Http\Controllers\TicketController;

$ticketNumber = PublicCodeGenerator::routePattern(PublicCodeEntity::Ticket);

// ── Customer surface ─────────────────────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1')
    ->group(function () use ($ticketNumber) {
        // The active categories a customer can file under.
        Route::get('ticket-categories', [TicketCategoryController::class, 'index']);

        Route::prefix('tickets')->group(function () use ($ticketNumber) {
            Route::get('/', [TicketController::class, 'index']);
            Route::post('/', [TicketController::class, 'store']);
            Route::get('/{ticketNumber}', [TicketController::class, 'show'])->where('ticketNumber', $ticketNumber);
            Route::post('/{ticketNumber}/messages', [TicketController::class, 'reply'])->where('ticketNumber', $ticketNumber);
            Route::post('/{ticketNumber}/close', [TicketController::class, 'close'])->where('ticketNumber', $ticketNumber);
        });
    });

// ── Support-agent surface (only tickets assigned to the caller) ──────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/support/tickets')
    ->group(function () use ($ticketNumber) {
        Route::get('/', [SupportTicketController::class, 'index']);
        Route::get('/{ticketNumber}', [SupportTicketController::class, 'show'])->where('ticketNumber', $ticketNumber);
        Route::post('/{ticketNumber}/messages', [SupportTicketController::class, 'reply'])->where('ticketNumber', $ticketNumber);
        Route::patch('/{ticketNumber}/status', [SupportTicketController::class, 'changeStatus'])->where('ticketNumber', $ticketNumber);
        Route::post('/{ticketNumber}/notes', [SupportTicketController::class, 'addNote'])->where('ticketNumber', $ticketNumber);
    });

// ── Admin surface ────────────────────────────────────────────────────────────
Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
    ->prefix('api/v1/admin')
    ->group(function () use ($ticketNumber) {
        // Support-user management (role grant/revoke + the assign picker).
        Route::get('support-users', [AdminSupportUserController::class, 'index']);
        Route::post('users/{user}/roles/support', [AdminSupportUserController::class, 'grant'])->whereNumber('user');
        Route::delete('users/{user}/roles/support', [AdminSupportUserController::class, 'revoke'])->whereNumber('user');

        // Category management.
        Route::get('ticket-categories', [AdminTicketCategoryController::class, 'index']);
        Route::post('ticket-categories', [AdminTicketCategoryController::class, 'store']);
        Route::patch('ticket-categories/{ticketCategory}', [AdminTicketCategoryController::class, 'update'])->whereNumber('ticketCategory');
        Route::delete('ticket-categories/{ticketCategory}', [AdminTicketCategoryController::class, 'destroy'])->whereNumber('ticketCategory');

        // Tickets.
        Route::prefix('tickets')->group(function () use ($ticketNumber) {
            Route::get('/', [AdminTicketController::class, 'index']);
            Route::get('/{ticketNumber}', [AdminTicketController::class, 'show'])->where('ticketNumber', $ticketNumber);
            Route::post('/{ticketNumber}/messages', [AdminTicketController::class, 'reply'])->where('ticketNumber', $ticketNumber);
            Route::post('/{ticketNumber}/notes', [AdminTicketController::class, 'addNote'])->where('ticketNumber', $ticketNumber);
            Route::patch('/{ticketNumber}/status', [AdminTicketController::class, 'changeStatus'])->where('ticketNumber', $ticketNumber);
            Route::post('/{ticketNumber}/assign', [AdminTicketController::class, 'assign'])->where('ticketNumber', $ticketNumber);
        });
    });
