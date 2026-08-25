<?php

namespace Modules\Review\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ReviewPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Writing reviews is self-service: every customer (and admin) may.
        $customerPermissions = [
            'review.create',
        ];

        $adminOnlyPermissions = [
            'review.view-admin',
            'review.moderate',
        ];

        foreach ([...$customerPermissions, ...$adminOnlyPermissions] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        if ($adminRole) {
            $adminRole->givePermissionTo([...$customerPermissions, ...$adminOnlyPermissions]);
        }

        $customerRole = Role::where('name', 'customer')->where('guard_name', 'web')->first();

        if ($customerRole) {
            $customerRole->givePermissionTo($customerPermissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
