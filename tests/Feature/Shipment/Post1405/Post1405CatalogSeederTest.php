<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment\Post1405;

use Illuminate\Support\Facades\DB;
use Modules\Shipment\Domain\Models\PostalService1405;
use Modules\Shipment\Domain\Models\PostalTariff1405;
use Modules\Shipment\Domain\Models\PostTariff;
use Modules\Shipment\Domain\Models\ShippingParameter;
use Modules\Shipment\Domain\Postal\Post1405Catalog;
use Modules\Shipment\Domain\Postal\PostalZone;
use Modules\Shipment\Infrastructure\Persistence\Post1405CatalogVerifier;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostalService1405Seeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostalTariff1405Seeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostTariffSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShippingParameterSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipment\ShipmentTestCase;

/**
 * The 1405 catalog as seeded: 21 services, 66 explicit tariff rows, exact amounts,
 * closed weight brackets, idempotent and non-destructive re-seeding.
 */
class Post1405CatalogSeederTest extends ShipmentTestCase
{
    private function seedCatalog(): void
    {
        $this->seed(PostalService1405Seeder::class);
        $this->seed(PostalTariff1405Seeder::class);
    }

    private function rate(string $service, string $zone, int $from): PostalTariff1405
    {
        return PostalTariff1405::query()
            ->where(['service_code' => $service, 'zone_code' => $zone, 'weight_from' => $from, 'tariff_year' => 1405])
            ->firstOrFail();
    }

    #[Test]
    public function it_seeds_exactly_21_services_and_66_tariff_rows(): void
    {
        $this->seedCatalog();

        $this->assertSame(21, PostalService1405::count());
        $this->assertSame(66, PostalTariff1405::count());
        $this->assertSame(66, PostalTariff1405::where('tariff_year', 1405)->count());
        $this->assertEqualsCanonicalizing(Post1405Catalog::codes(), PostalService1405::pluck('code')->all());
    }

    #[Test]
    public function the_verifier_reports_a_clean_catalog(): void
    {
        $this->seedCatalog();

        $report = app(Post1405CatalogVerifier::class)->verify();

        $this->assertTrue($report['ok']);
        $this->assertSame(21, $report['service_count']);
        $this->assertSame(66, $report['tariff_count']);
        $this->assertSame(0, $report['negative_prices']);
        $this->assertSame([], $report['overlapping_brackets']);
        $this->assertSame([], $report['unrecognized_service_codes']);
        $this->assertSame([], $report['unrecognized_zones']);
        $this->assertSame([], $report['unrecognized_tariff_years']);
        $this->assertSame([], $report['differences']);
        $this->assertSame(0, $report['derived_service_rows']);
        // The 27,000-toman same-province 3–5 kg rate is flagged, not corrected.
        $this->assertCount(1, $report['flagged_rates']);
    }

    #[Test]
    public function services_carry_exact_persian_titles_and_classification(): void
    {
        $this->seedCatalog();

        $expected = [
            'post_economy_unregistered' => ['پستنامه غیرثبتی (اسناد)', 'non_priority', 'document', 'envelope'],
            'post_registered_document' => ['پستنامه ثبتی پاکت (اسناد)', 'non_priority', 'document', 'envelope'],
            'post_registered_packet' => ['پستنامه ثبتی پاکت جوف / بسته', 'non_priority', 'goods', 'packet'],
            'post_registered_parcel' => ['امانت پستی ثبتی', 'non_priority', 'goods', 'parcel'],
            'post_mbag_unregistered' => ['کیسه M غیرثبتی', 'specialized', 'printed_matter', 'bag'],
            'post_mbag_registered' => ['کیسه M ثبتی', 'specialized', 'printed_matter', 'bag'],
            'post_periodical' => ['مطبوعات', 'specialized', 'periodical', 'bundle'],
            'post_urban_3h_envelope' => ['شهری ۳ ساعته — پاکت', 'urban', 'document', 'envelope'],
            'post_urban_3h_parcel' => ['شهری ۳ ساعته — بسته', 'urban', 'goods', 'parcel'],
            'post_urban_sameday_envelope' => ['شهری همان‌روز — پاکت', 'urban', 'document', 'envelope'],
            'post_urban_sameday_parcel' => ['شهری همان‌روز — بسته', 'urban', 'goods', 'parcel'],
            'post_urban_nextday_envelope' => ['شهری روز بعد — پاکت', 'urban', 'document', 'envelope'],
            'post_urban_nextday_parcel' => ['شهری روز بعد — بسته', 'urban', 'goods', 'parcel'],
            'post_intercity_sameday_envelope' => ['بین‌شهری همان‌روز — پاکت', 'intercity', 'document', 'envelope'],
            'post_intercity_sameday_parcel' => ['بین‌شهری همان‌روز — بسته', 'intercity', 'goods', 'parcel'],
            'post_intercity_d1_envelope' => ['ویژه D+1 — پاکت', 'intercity', 'document', 'envelope'],
            'post_intercity_d1_parcel' => ['ویژه D+1 — بسته', 'intercity', 'goods', 'parcel'],
            'post_intercity_d2_envelope' => ['اکسپرس D+2 — پاکت', 'intercity', 'document', 'envelope'],
            'post_intercity_d2_parcel' => ['اکسپرس D+2 — بسته', 'intercity', 'goods', 'parcel'],
            'post_intercity_d3_envelope' => ['پیشتاز D+3 — پاکت', 'intercity', 'document', 'envelope'],
            'post_intercity_d3_parcel' => ['پیشتاز D+3 — بسته', 'intercity', 'goods', 'parcel'],
        ];

        foreach ($expected as $code => [$title, $scope, $content, $package]) {
            $row = PostalService1405::findOrFail($code);
            $this->assertSame($title, $row->title_fa, $code);
            $this->assertSame($scope, $row->scope, $code);
            $this->assertSame($content, $row->content_kind, $code);
            $this->assertSame($package, $row->package_kind, $code);
            $this->assertSame(1405, $row->tariff_year, $code);
        }
    }

    #[Test]
    public function rows_per_service_use_the_right_zone_vocabulary(): void
    {
        $this->seedCatalog();

        $counts = PostalTariff1405::query()->selectRaw('service_code, COUNT(*) c')->groupBy('service_code')->pluck('c', 'service_code')->map(fn ($c) => (int) $c)->all();

        $this->assertSame(6, $counts['post_economy_unregistered']);
        $this->assertSame(3, $counts['post_registered_document']);
        $this->assertSame(6, $counts['post_registered_packet']);
        $this->assertSame(9, $counts['post_registered_parcel']);
        foreach (['post_urban_3h', 'post_urban_sameday', 'post_urban_nextday', 'post_intercity_sameday', 'post_intercity_d1', 'post_intercity_d2', 'post_intercity_d3'] as $family) {
            $this->assertSame(3, $counts["{$family}_envelope"]);
            $this->assertSame(3, $counts["{$family}_parcel"]);
        }

        // Derived services get no fabricated fixed-price rows.
        foreach (['post_mbag_unregistered', 'post_mbag_registered', 'post_periodical'] as $derived) {
            $this->assertArrayNotHasKey($derived, $counts);
        }

        $this->assertEqualsCanonicalizing(
            PostalZone::urbanCodes(),
            PostalTariff1405::where('service_code', 'like', 'post_urban_%')->distinct()->pluck('zone_code')->all(),
        );
        $this->assertEqualsCanonicalizing(
            PostalZone::distanceCodes(),
            PostalTariff1405::where('service_code', 'not like', 'post_urban_%')->distinct()->pluck('zone_code')->all(),
        );
    }

    #[Test]
    public function amounts_are_the_published_rates_in_tomans(): void
    {
        $this->seedCatalog();

        // Non-priority (PDF p.2).
        $this->assertSame(7500, $this->rate('post_economy_unregistered', 'same_province', 1)->base_price_toman);
        $this->assertSame(22500, $this->rate('post_economy_unregistered', 'non_neighbor', 1001)->base_price_toman);
        $this->assertSame(22900, $this->rate('post_registered_document', 'non_neighbor', 1)->base_price_toman);
        $this->assertSame(28500, $this->rate('post_registered_packet', 'neighbor', 501)->base_price_toman);
        $this->assertSame(41200, $this->rate('post_registered_packet', 'non_neighbor', 1001)->base_price_toman);
        $this->assertSame(44900, $this->rate('post_registered_parcel', 'non_neighbor', 2001)->base_price_toman);
        $this->assertSame(52500, $this->rate('post_registered_parcel', 'non_neighbor', 3001)->base_price_toman);
        // The flagged rate is reproduced verbatim.
        $this->assertSame(27000, $this->rate('post_registered_parcel', 'same_province', 3001)->base_price_toman);

        $above5 = $this->rate('post_registered_parcel', 'non_neighbor', 5001);
        $this->assertSame(52500, $above5->base_price_toman);
        $this->assertSame(10000, $above5->extra_per_started_kg_toman);
        $this->assertSame(5000, $above5->extra_above_grams);
        $this->assertNull($above5->weight_to);
        $this->assertSame(7500, $this->rate('post_registered_parcel', 'same_province', 5001)->extra_per_started_kg_toman);
        $this->assertSame(9500, $this->rate('post_registered_parcel', 'neighbor', 5001)->extra_per_started_kg_toman);

        // Urban (PDF p.3 T3).
        $this->assertSame(118000, $this->rate('post_urban_3h_parcel', 'metropolis', 1)->base_price_toman);
        $this->assertSame(11000, $this->rate('post_urban_3h_parcel', 'other_city', 1)->extra_per_started_kg_toman);
        $this->assertSame(88000, $this->rate('post_urban_sameday_parcel', 'province_center', 1)->base_price_toman);
        $this->assertSame(58000, $this->rate('post_urban_nextday_envelope', 'other_city', 1)->base_price_toman);

        // Intercity (PDF p.3 T4).
        $this->assertSame(287000, $this->rate('post_intercity_sameday_parcel', 'non_neighbor', 1)->base_price_toman);
        $this->assertSame(16500, $this->rate('post_intercity_d1_parcel', 'neighbor', 1)->extra_per_started_kg_toman);
        $this->assertSame(110000, $this->rate('post_intercity_d2_parcel', 'non_neighbor', 1)->base_price_toman);
        $d3Parcel = $this->rate('post_intercity_d3_parcel', 'non_neighbor', 1);
        $this->assertSame(100000, $d3Parcel->base_price_toman);
        $this->assertSame(17500, $d3Parcel->extra_per_started_kg_toman);
        $this->assertSame(1000, $d3Parcel->extra_above_grams);
        $this->assertSame(90000, $this->rate('post_intercity_d3_envelope', 'non_neighbor', 1)->base_price_toman);
    }

    #[Test]
    public function weight_brackets_are_closed_and_explicit(): void
    {
        $this->seedCatalog();

        $parcel = PostalTariff1405::where(['service_code' => 'post_registered_parcel', 'zone_code' => 'neighbor'])->orderBy('weight_from')->get();
        $this->assertSame([[2001, 3000], [3001, 5000], [5001, null]], $parcel->map(fn ($r) => [$r->weight_from, $r->weight_to])->all());

        $packet = PostalTariff1405::where(['service_code' => 'post_registered_packet', 'zone_code' => 'neighbor'])->orderBy('weight_from')->get();
        $this->assertSame([[501, 1000], [1001, 2000]], $packet->map(fn ($r) => [$r->weight_from, $r->weight_to])->all());

        $this->assertSame(500, $this->rate('post_registered_document', 'neighbor', 1)->weight_to);
        // The D+3 envelope is "up to 500 g", every other priority envelope "up to 1 kg".
        $this->assertSame(500, $this->rate('post_intercity_d3_envelope', 'same_province', 1)->weight_to);
        $this->assertSame(1000, $this->rate('post_intercity_d2_envelope', 'same_province', 1)->weight_to);

        // Every priority parcel is open-ended above 1 kg; no other row has an extra rate
        // without a threshold.
        foreach (PostalTariff1405::where('service_code', 'like', 'post_%_parcel')->where('service_code', '!=', 'post_registered_parcel')->get() as $row) {
            $this->assertNull($row->weight_to);
            $this->assertSame(1000, $row->extra_above_grams);
            $this->assertGreaterThan(0, $row->extra_per_started_kg_toman);
        }
        $this->assertSame(0, PostalTariff1405::where('extra_per_started_kg_toman', '>', 0)->whereNull('extra_above_grams')->count());
    }

    #[Test]
    public function reseeding_is_idempotent(): void
    {
        $this->seedCatalog();
        $this->seedCatalog();

        $this->assertSame(21, PostalService1405::count());
        $this->assertSame(66, PostalTariff1405::count());
        $this->assertSame(0, PostalTariff1405::query()
            ->selectRaw('service_code, zone_code, weight_from, tariff_year, COUNT(*) c')
            ->groupBy('service_code', 'zone_code', 'weight_from', 'tariff_year')
            ->havingRaw('COUNT(*) > 1')->get()->count());
    }

    #[Test]
    public function an_operator_edited_price_survives_a_reseed_and_is_reported(): void
    {
        $this->seedCatalog();
        $row = $this->rate('post_intercity_d3_parcel', 'non_neighbor', 1);
        $row->update(['base_price_toman' => 95000]);

        $this->seed(PostalTariff1405Seeder::class);

        $this->assertSame(95000, $row->fresh()->base_price_toman);
        $report = app(Post1405CatalogVerifier::class)->verify();
        $this->assertTrue($report['ok'], 'An edited price is a difference, not a structural error.');
        $this->assertCount(1, $report['differences']);
        $this->assertStringContainsString('post_intercity_d3_parcel/non_neighbor/1.base_price_toman', $report['differences'][0]);
    }

    #[Test]
    public function a_missing_row_overlapping_a_hand_made_bracket_is_not_inserted(): void
    {
        $this->seedCatalog();
        // Production hand-edit: the 3–5 kg bracket removed and replaced by a 2.5–6 kg one.
        $this->rate('post_registered_parcel', 'neighbor', 3001)->delete();
        PostalTariff1405::create([
            'service_code' => 'post_registered_parcel', 'zone_code' => 'neighbor', 'weight_from' => 2500, 'weight_to' => 6000,
            'base_price_toman' => 40000, 'extra_per_started_kg_toman' => 0, 'extra_above_grams' => null, 'tariff_year' => 1405,
        ]);

        $this->seed(PostalTariff1405Seeder::class);

        $this->assertFalse(PostalTariff1405::where(['service_code' => 'post_registered_parcel', 'zone_code' => 'neighbor', 'weight_from' => 3001])->exists());
        $report = app(Post1405CatalogVerifier::class)->verify();
        $this->assertFalse($report['ok']);
        $this->assertNotEmpty($report['overlapping_brackets']);
        $this->assertContains('post_registered_parcel/neighbor/3001', $report['missing_tariffs']);
    }

    #[Test]
    public function the_verifier_flags_unknown_codes_zones_and_years(): void
    {
        $this->seedCatalog();
        DB::table('postal_services_1405')->insert([
            'code' => 'post_teleport', 'title_fa' => 'x', 'scope' => 'intercity', 'content_kind' => 'goods', 'package_kind' => 'parcel',
            'rating_rule' => 'x', 'pdf_reference' => 'x', 'eligibility_policy' => 'open', 'tariff_year' => 1404,
        ]);
        PostalTariff1405::create([
            'service_code' => 'post_intercity_d3_parcel', 'zone_code' => 'metropolis', 'weight_from' => 1,
            'weight_to' => null, 'base_price_toman' => 1, 'extra_per_started_kg_toman' => 0, 'extra_above_grams' => null, 'tariff_year' => 1405,
        ]);

        $report = app(Post1405CatalogVerifier::class)->verify();

        $this->assertFalse($report['ok']);
        $this->assertContains('post_teleport', $report['unrecognized_service_codes']);
        $this->assertContains('post_intercity_d3_parcel/metropolis', $report['unrecognized_zones']);
        $this->assertContains(1404, $report['unrecognized_tariff_years']);
    }

    #[Test]
    public function rerunning_the_migrations_on_existing_tables_is_non_destructive(): void
    {
        $this->seedCatalog();
        $this->rate('post_intercity_d3_parcel', 'non_neighbor', 1)->update(['base_price_toman' => 95000]);

        foreach (glob(base_path('Modules/Shipment/Infrastructure/Persistence/Migrations/2026_11_19_00000*.php')) as $file) {
            (require $file)->up();
        }

        // Rollback of the catalog tables is deliberately a no-op.
        (require base_path('Modules/Shipment/Infrastructure/Persistence/Migrations/2026_11_19_000002_create_postal_tariffs_1405_table.php'))->down();
        (require base_path('Modules/Shipment/Infrastructure/Persistence/Migrations/2026_11_19_000001_create_postal_services_1405_table.php'))->down();

        $this->assertSame(21, PostalService1405::count());
        $this->assertSame(66, PostalTariff1405::count());
        $this->assertSame(95000, $this->rate('post_intercity_d3_parcel', 'non_neighbor', 1)->base_price_toman);
    }

    #[Test]
    public function the_retired_legacy_seeder_never_recreates_obsolete_brackets(): void
    {
        $this->seed(PostTariffSeeder::class);
        $this->assertSame(0, PostTariff::count());

        // Existing historical rows are left exactly as they are.
        $legacy = PostTariff::create([
            'service_type' => 'post_standard', 'package_type' => 'standard', 'weight_from' => 0, 'weight_to' => 1000,
            'distance_type' => 'neighbor', 'base_price' => 12345, 'extra_weight_price' => 0,
        ]);
        $this->seed(PostTariffSeeder::class);
        $this->seedCatalog();

        $this->assertSame(1, PostTariff::count());
        $this->assertSame(12345, $legacy->fresh()->base_price);
    }

    #[Test]
    public function shipping_parameters_are_seeded_in_tomans_without_overwriting_operator_values(): void
    {
        $this->seed(ShippingParameterSeeder::class);

        $value = fn (string $key) => ShippingParameter::where(['carrier' => 'post', 'key' => $key])->value('value');
        $this->assertSame('25500', $value('island_extra_per_kg'));
        $this->assertSame('25', $value('fragile_percentage'));
        $this->assertSame('5', $value('large_province_percentage'));
        $this->assertSame('20', $value('tehran_percentage'));
        $this->assertSame('15', $value('metropolitan_percentage'));

        ShippingParameter::where(['carrier' => 'post', 'key' => 'island_extra_per_kg'])->update(['value' => '255000']);
        $this->seed(ShippingParameterSeeder::class);

        $this->assertSame('255000', $value('island_extra_per_kg'));
        $this->assertSame(5, ShippingParameter::count());
    }

    #[Test]
    public function the_verify_command_passes_on_a_clean_catalog_and_matching_geography(): void
    {
        $this->seedPost1405();

        $this->artisan('shipping:post-1405:verify')->assertSuccessful();
    }

    #[Test]
    public function the_verify_command_fails_when_a_configured_id_is_missing(): void
    {
        $this->seedPost1405();
        config(['shipping.surcharges.metropolitan_city_ids' => [self::AHVAZ, 777777]]);

        $this->artisan('shipping:post-1405:verify')->assertFailed();
    }
}
