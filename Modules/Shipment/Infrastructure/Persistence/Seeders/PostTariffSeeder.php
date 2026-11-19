<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;

/**
 * RETIRED. This seeder used to insert illustrative `post_standard` / `post_express`
 * brackets into the legacy `post_tariffs` table. Those methods are retired and are
 * never priced again, and production's `post_tariffs` rows were edited by hand — so
 * this seeder now deliberately does nothing: it never recreates obsolete brackets and
 * never touches existing historical rows.
 *
 * The class is kept so existing seeder references keep resolving. Iran Post 1405
 * pricing is seeded by PostalService1405Seeder + PostalTariff1405Seeder.
 */
class PostTariffSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->line('PostTariffSeeder is retired (legacy post_tariffs left untouched). Use PostalTariff1405Seeder.');
    }
}
