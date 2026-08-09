<?php

namespace Modules\Promotion\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Promotion is an entirely back-office capability: nothing a customer does — reading
 * products, browsing campaigns, checking a coupon, paying — requires any promotion.*
 * permission, so the customer role is deliberately left untouched here.
 */
class PromotionPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Read access to every admin promotion surface (discounts, coupons,
            // campaigns, redemption history).
            'promotion.view-admin',
            'promotion.create',
            'promotion.update',
            'promotion.delete',
            // Coupon and campaign *mutations* are separated from discount CRUD so a
            // marketing operator can be given code/merchandising control without the
            // ability to rewrite pricing rules.
            'promotion.coupon.manage',
            'promotion.campaign.manage',
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

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
