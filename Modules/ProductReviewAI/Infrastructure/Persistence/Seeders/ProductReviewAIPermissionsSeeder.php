<?php

namespace Modules\ProductReviewAI\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The feature is admin-only. The single capability is granted to `admin` and to
 * no one else — never to `customer`, never to `support`.
 */
class ProductReviewAIPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => 'product-review-ai.manage',
            'guard_name' => 'web',
        ]);

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        $adminRole?->givePermissionTo('product-review-ai.manage');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
