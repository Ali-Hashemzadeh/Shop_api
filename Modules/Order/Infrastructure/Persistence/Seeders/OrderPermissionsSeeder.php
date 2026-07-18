<?php

namespace Modules\Order\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OrderPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'order.create',
            'order.view-own',
            'order.view-admin',
            'order.cancel-admin',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }

        $customerRole = Role::where('name', 'customer')->where('guard_name', 'web')->first();

        if ($customerRole) {
            $customerRole->givePermissionTo(['order.create', 'order.view-own']);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
