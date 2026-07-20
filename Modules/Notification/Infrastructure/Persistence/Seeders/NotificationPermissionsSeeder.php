<?php

namespace Modules\Notification\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class NotificationPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'notification.view-own',
            'notification.mark-read-own',
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

        // Notifications are self-service — every customer reads and marks their own.
        $customerRole = Role::where('name', 'customer')->where('guard_name', 'web')->first();

        if ($customerRole) {
            $customerRole->givePermissionTo($permissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
