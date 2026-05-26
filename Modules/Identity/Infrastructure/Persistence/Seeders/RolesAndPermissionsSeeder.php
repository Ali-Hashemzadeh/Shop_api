<?php

namespace Modules\Identity\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'profile.view-own',
            'profile.update-own',
            'profile.view-any',
            'profile.update-any',
            'address.view-own',
            'address.create-own',
            'address.update-own',
            'address.delete-own',
            'address.set-default-own',
            'address.view-any',
            'address.update-any',
            'address.delete-any',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $customerRole = Role::firstOrCreate([
            'name' => 'customer',
            'guard_name' => 'web',
        ]);

        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $customerPermissions = [
            'profile.view-own',
            'profile.update-own',
            'address.view-own',
            'address.create-own',
            'address.update-own',
            'address.delete-own',
            'address.set-default-own',
        ];

        $customerRole->syncPermissions($customerPermissions);
        $adminRole->syncPermissions(Permission::where('guard_name', 'web')->pluck('name')->all());

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
