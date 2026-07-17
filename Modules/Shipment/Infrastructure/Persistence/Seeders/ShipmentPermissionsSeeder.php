<?php

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ShipmentPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'shipment.view-own',
            'shipment.view-admin',
            'shipment.start-preparing',
            'shipment.post.mark-ready',
            'shipment.post.hand-over',
            'shipment.delivery.mark-ready',
            'shipment.delivery.dispatch',
            'shipment.delivery.complete',
            'shipment.delivery.fail',
            'shipment.delivery.reschedule',
            'shipment.pickup.mark-ready',
            'shipment.pickup.complete',
            'shipment.slot.view-admin',
            'shipment.slot.manage',
            'shipment.slot.close',
            'shipment.slot.reserve-capacity',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        $adminRole?->givePermissionTo($permissions);

        // Customers may view their own shipments.
        $customerRole = Role::where('name', 'customer')->where('guard_name', 'web')->first();
        $customerRole?->givePermissionTo(['shipment.view-own']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
