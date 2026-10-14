<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\AdminPermissionController;
use Modules\Identity\Infrastructure\Http\Controllers\AdminRoleController;
use Modules\Identity\Infrastructure\Http\Controllers\AdminUserRoleController;

// ── Admin RBAC surface (`rbac.view` / `rbac.manage`) ─────────────────────────
//
// The `{role}` parameter is a role *name* resolved against the `web` guard;
// unknown names are 404. The `admin` role is read-only here: it can be listed
// (and shows up in pickers), but its permission set cannot be edited and the
// role itself cannot be assigned or revoked — those are seeder/CLI concerns.
Route::prefix('admin')->group(function () {
    Route::get('roles', [AdminRoleController::class, 'index']);
    Route::get('roles/{role}', [AdminRoleController::class, 'show']);
    Route::post('roles/{role}/permissions', [AdminRoleController::class, 'grantPermissions']);
    Route::delete('roles/{role}/permissions', [AdminRoleController::class, 'revokePermissions']);

    // The role name travels in the body on purpose: a `{role}` segment under
    // `users/{user}/roles` would shadow Ticket's `.../roles/support` routes
    // (Identity's routes register first).
    Route::post('users/{user}/roles', [AdminUserRoleController::class, 'assign']);
    Route::delete('users/{user}/roles', [AdminUserRoleController::class, 'revoke']);

    Route::get('permissions', [AdminPermissionController::class, 'index']);
});
