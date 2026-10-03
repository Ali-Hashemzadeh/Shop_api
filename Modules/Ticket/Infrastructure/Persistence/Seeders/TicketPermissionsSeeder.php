<?php

namespace Modules\Ticket\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ticket permissions, plus the `support` role.
 *
 * The `support` role is created here (Ticket owns the concept) and carries only
 * the agent permissions — like `delivery`, it is a bundle *added* to an account,
 * never a replacement for its `customer`/`admin` roles. Admin receives every
 * ticket permission; customers receive only the self-service ones.
 */
class TicketPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $customerPermissions = [
            'ticket.create',
            'ticket.view-own',
            'ticket.reply-own',
            'ticket.close-own',
        ];

        $supportPermissions = [
            'ticket.view-assigned',
            'ticket.reply-admin',
            'ticket.change-status',
            'ticket.add-internal-note',
        ];

        $adminOnlyPermissions = [
            'ticket.view-admin',
            'ticket.assign',
            'ticket.manage-categories',
            'ticket.manage-support-users',
        ];

        $all = [...$customerPermissions, ...$supportPermissions, ...$adminOnlyPermissions];

        foreach ($all as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Created empty and only ever *added* to an account (never synced), so
        // reseeding cannot wipe the other roles a support agent also holds.
        $supportRole = Role::firstOrCreate([
            'name' => 'support',
            'guard_name' => 'web',
        ]);
        $supportRole->givePermissionTo($supportPermissions);

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            // Admins can do everything a support agent can, plus the admin-only surface.
            $adminRole->givePermissionTo($all);
        }

        $customerRole = Role::where('name', 'customer')->where('guard_name', 'web')->first();
        if ($customerRole) {
            $customerRole->givePermissionTo($customerPermissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
