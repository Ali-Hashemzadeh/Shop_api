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
            'shipment.delivery.assign',
            'shipment.delivery.view-assigned',
            'shipment.delivery.complete-assigned',
            'shipment.delivery.resend-code',
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

        // A delivery worker sees and completes only the shipments assigned to them.
        // Deliberately absent: shipment.view-admin (the whole store's shipments),
        // dispatch/mark-ready/fail/reschedule (the store decides what goes out and
        // when), and every slot permission. A courier carries parcels; they do not
        // run fulfillment. `shipment.view-own` is not granted here either — it comes
        // with the `customer` role every delivery worker also holds, for their own
        // shopping.
        $deliveryRole = Role::where('name', 'delivery')->where('guard_name', 'web')->first();
        $deliveryRole?->givePermissionTo([
            'shipment.delivery.view-assigned',
            'shipment.delivery.complete-assigned',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
