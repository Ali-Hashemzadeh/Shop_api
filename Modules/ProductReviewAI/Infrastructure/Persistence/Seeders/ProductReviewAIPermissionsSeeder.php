<?php

namespace Modules\ProductReviewAI\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The feature is admin-only. Every capability is granted to `admin` and to no
 * one else — never to `customer`, never to `support`.
 *
 *   product-review-ai.manage     the whole workflow + monitoring (list, detail,
 *                                stats, sanitized execution logs)
 *   product-review-ai.view-logs  elevated: redacted full request/response
 *                                payloads in the execution logs (`diagnostics=1`)
 *   product-review-ai.retry      elevated: manually retry a failed generation
 *
 * The elevated permissions are additive to `manage` (both are required); they
 * can be granted per user/role through the RBAC API without granting them to
 * everyone who can manage AI reviews.
 */
class ProductReviewAIPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'product-review-ai.manage',
        'product-review-ai.view-logs',
        'product-review-ai.retry',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        $adminRole?->givePermissionTo(self::PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
