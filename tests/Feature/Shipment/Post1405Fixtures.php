<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostalService1405Seeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostalTariff1405Seeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShippingParameterSeeder;

/**
 * Iran Post 1405 test geography using the PRODUCTION provinces.id / cities.id values
 * (export of 2026-11-18), so tests exercise exactly the ids config/shipping.php ships
 * with. Ids marked "test-only" are not production ids — they stand in for "some other
 * city/province not on any list".
 */
trait Post1405Fixtures
{
    /** Default postal method for tests that just need "a postal shipment". */
    protected const POSTAL_METHOD = 'post_intercity_d3_parcel';

    protected const QOM_PROVINCE = 19;

    protected const QOM = 599;

    protected const QOM_OTHER_CITY = 9001;     // test-only city in Qom province

    protected const TEHRAN = 219;

    protected const TEHRAN_OTHER_CITY = 9002;  // test-only city in Tehran province

    protected const KARAJ = 162;

    protected const MASHHAD = 347;

    protected const ISFAHAN = 115;

    protected const SHIRAZ = 530;

    protected const TABRIZ = 9;

    protected const AHVAZ = 382;

    protected const KERMANSHAH = 703;

    protected const DEZFUL = 9003;             // test-only, Khuzestan, not metropolitan

    protected const ARAK = 9004;               // test-only, Markazi (neighbour of Qom)

    protected const GORGAN = 9005;             // test-only, an unlisted province

    /** @var array<int, array{0: string, 1: int}> city id => [name, province id] */
    private const FIXTURE_CITIES = [
        599 => ['قم', 19], 9001 => ['جعفریه', 19],
        219 => ['تهران', 8], 9002 => ['ری', 8],
        162 => ['کرج', 5],
        347 => ['مشهد', 11],
        115 => ['اصفهان', 4],
        530 => ['شیراز', 17],
        9 => ['تبریز', 1],
        382 => ['اهواز', 13], 9003 => ['دزفول', 13],
        703 => ['کرمانشاه', 22],
        9004 => ['اراک', 28],
        9005 => ['گرگان', 24],
    ];

    /** @var array<int, string> */
    private const FIXTURE_PROVINCES = [
        19 => 'قم', 8 => 'تهران', 5 => 'البرز', 4 => 'اصفهان', 11 => 'خراسان رضوی', 17 => 'فارس',
        1 => 'آذربایجان شرقی', 13 => 'خوزستان', 22 => 'کرمانشاه', 28 => 'مرکزی', 15 => 'سمنان',
        21 => 'کرمان', 16 => 'سیستان و بلوچستان', 10 => 'خراسان جنوبی', 31 => 'یزد', 24 => 'گلستان',
    ];

    /**
     * Provinces, cities, Qom's neighbours (4, 8, 15, 28), the shipping parameters and
     * the 1405 catalog. Origin Qom (19 / 599); no local-delivery area (so postal is
     * priced everywhere unless a test configures one). Idempotent within a test.
     */
    protected function seedPost1405(): void
    {
        if (DB::table('provinces')->where('id', self::QOM_PROVINCE)->exists()) {
            return;
        }

        $now = now();

        foreach (self::FIXTURE_PROVINCES as $id => $name) {
            DB::table('provinces')->insert(['id' => $id, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (self::FIXTURE_CITIES as $id => [$name, $provinceId]) {
            DB::table('cities')->insert(['id' => $id, 'province_id' => $provinceId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach ([4, 8, 15, 28] as $neighbor) {
            DB::table('province_neighbors')->insert([
                'province_id' => self::QOM_PROVINCE, 'neighbor_province_id' => $neighbor, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->seed(ShippingParameterSeeder::class);
        $this->seed(PostalService1405Seeder::class);
        $this->seed(PostalTariff1405Seeder::class);

        config([
            'shipping.origin_province_id' => self::QOM_PROVINCE,
            'shipping.origin_city_id' => self::QOM,
            'shipment.local_delivery.province_ids' => [],
            'shipment.local_delivery.city_ids' => [],
        ]);
    }

    /** An address in a fixture city (province derived from the city). */
    protected function postalAddress(int $userId, int $cityId = self::AHVAZ): int
    {
        $this->seedPost1405();

        return $this->createAddress($userId, self::FIXTURE_CITIES[$cityId][1], $cityId);
    }

    protected function createWeightedVariant(string $sku, ?int $weightGrams, int $price = 50000, int $qty = 10): void
    {
        $product = Product::create([
            'title' => "Product {$sku}",
            'slug' => strtolower($sku),
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'base_price' => $price,
            'weight_grams' => $weightGrams,
            'is_default' => true,
            'attributes' => [],
        ]);

        InventoryStock::create(['sku' => $sku, 'quantity' => $qty, 'reserved_quantity' => 0]);
    }

    protected function isPostalMethod(string $code): bool
    {
        return ! in_array($code, ['local_delivery', 'in_person_pickup'], true);
    }
}
