<?php

namespace Modules\Identity\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Modules\Identity\Domain\Models\City;
use Modules\Identity\Domain\Models\Province;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/locations.json');

        if (! File::exists($path)) {
            throw new \Exception("locations.json not found at: {$path}");
        }

        $provinces = json_decode(File::get($path), true);

        if (! is_array($provinces)) {
            throw new \Exception('Invalid JSON structure in locations.json');
        }

        foreach ($provinces as $provinceData) {

            unset($provinceData['id']);

            $province = Province::firstOrCreate([
                'name' => $provinceData['name'],
            ]);

            foreach (($provinceData['cities'] ?? []) as $cityData) {

                unset($cityData['id']);

                City::firstOrCreate([
                    'province_id' => $province->id,
                    'name' => $cityData['name'],
                ]);
            }
        }
    }
}
