<?php

namespace Modules\Identity\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Models\User;

class DefaultUsersSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'ali.melmedas1383@gmail.com'],
            [
                'name' => 'MELMEDAS_Admin',
                'phone' => '09197238119',
                'password' => Hash::make('Qaz@18410'),
                'email_verified_at' => now(),
            ]
        );

        $admin->syncRoles(['admin']);

        $customer = User::updateOrCreate(
            ['email' => 'aliimail623@gmail.com'],
            [
                'name' => 'MELMEDAS_User',
                'phone' => '09981520581',
                'password' => Hash::make('Qaz@18410'),
                'email_verified_at' => now(),
            ]
        );

        $customer->syncRoles(['customer']);

        // A demo courier. Note the *pair* of roles: a delivery worker is a shopper
        // who also delivers, so they keep every customer capability and simply gain
        // the two assigned-shipment permissions on top.
        $delivery = User::updateOrCreate(
            ['email' => 'delivery@melmedas.test'],
            [
                'name' => 'MELMEDAS_Delivery',
                'last_name' => 'Courier',
                // Outside the 0912000000x block the Order demo customers use.
                'phone' => '09120009001',
                'password' => Hash::make('Qaz@18410'),
                'email_verified_at' => now(),
            ]
        );

        $delivery->syncRoles(['customer', 'delivery']);
    }
}
