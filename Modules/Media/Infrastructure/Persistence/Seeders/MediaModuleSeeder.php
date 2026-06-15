<?php

namespace Modules\Media\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;

class MediaModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            MediaPermissionsSeeder::class,
        ]);

    }
}
