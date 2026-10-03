<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\Province;
use Modules\Identity\Domain\Models\ProvinceNeighbor;
use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\Exceptions\ShippingTariffNotFoundException;
use Modules\Shipment\Domain\Models\PostTariff;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostTariffSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShippingParameterSeeder;
use Tests\TestCase;

class PostShippingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private int $origin;

    private int $neighbor;

    private int $far;

    protected function setUp(): void
    {
        parent::setUp();

        // Origin, a bordering province, and a far province.
        $this->origin = Province::create(['name' => 'تهران'])->id;
        $this->neighbor = Province::create(['name' => 'قم'])->id;
        $this->far = Province::create(['name' => 'فارس'])->id;

        ProvinceNeighbor::create(['province_id' => $this->origin, 'neighbor_province_id' => $this->neighbor]);

        // Real tariff brackets + tunable parameters (toman).
        $this->seed(ShippingParameterSeeder::class);
        $this->seed(PostTariffSeeder::class);
    }

    private function calculator(): ShippingCostCalculatorInterface
    {
        return app(ShippingCostCalculatorInterface::class);
    }

    private function request(int $destination, int $weightGrams, string $service = 'post_standard', string $package = 'standard'): ShippingCostRequestDTO
    {
        return new ShippingCostRequestDTO(
            originProvinceId: $this->origin,
            destinationProvinceId: $destination,
            weightGrams: $weightGrams,
            serviceType: $service,
            packageType: $package,
        );
    }

    /** @test */
    public function it_prices_a_same_province_parcel_from_the_matching_bracket(): void
    {
        // 500g falls in [0,1000) same_province standard => base 40000, no extras.
        $result = $this->calculator()->calculate($this->request($this->origin, 500));

        $this->assertSame('same_province', $result->distanceType);
        $this->assertSame(40000, $result->basePrice);
        $this->assertSame(40000, $result->total);
    }

    /** @test */
    public function it_prices_a_neighbor_parcel_higher_than_same_province(): void
    {
        // 500g neighbor standard => base 50000.
        $result = $this->calculator()->calculate($this->request($this->neighbor, 500));

        $this->assertSame('neighbor', $result->distanceType);
        $this->assertSame(50000, $result->total);
    }

    /** @test */
    public function it_prices_a_non_neighbor_parcel_highest(): void
    {
        // 500g non_neighbor standard => base 65000.
        $result = $this->calculator()->calculate($this->request($this->far, 500));

        $this->assertSame('non_neighbor', $result->distanceType);
        $this->assertSame(65000, $result->total);
    }

    /** @test */
    public function it_selects_the_correct_weight_bracket(): void
    {
        // 2000g falls in [1000,3000) same_province standard => base 55000.
        $result = $this->calculator()->calculate($this->request($this->origin, 2000));

        $this->assertSame(55000, $result->total);
    }

    /** @test */
    public function it_adds_extra_weight_on_the_open_ended_bracket(): void
    {
        // 5000g non_neighbor: top bracket from=3000 base 120000, extra 30000/kg.
        // over = 5000-3000 = 2000g => ceil(2) = 2kg => 120000 + 2*30000 = 180000.
        $result = $this->calculator()->calculate($this->request($this->far, 5000));

        $this->assertSame(120000, $result->basePrice);
        $this->assertSame(60000, $result->extraWeightCost);
        $this->assertSame(180000, $result->total);
    }

    /** @test */
    public function it_applies_the_island_per_kg_surcharge(): void
    {
        config(['shipping.province_groups.islands' => [$this->neighbor]]);

        // 2000g neighbor: base 70000 (bracket [1000,3000)); island 255000/kg * ceil(2) = 510000.
        $result = $this->calculator()->calculate($this->request($this->neighbor, 2000));

        $this->assertSame(70000, $result->basePrice);
        $this->assertSame(510000, $result->islandSurcharge);
        $this->assertSame(580000, $result->total);
    }

    /** @test */
    public function it_applies_the_tehran_alborz_percentage(): void
    {
        config(['shipping.province_groups.tehran_alborz' => [$this->origin]]);

        // 500g same_province base 40000; +20% = 8000 => 48000.
        $result = $this->calculator()->calculate($this->request($this->origin, 500));

        $this->assertSame(8000, $result->tehranSurcharge);
        $this->assertSame(48000, $result->total);
    }

    /** @test */
    public function it_applies_the_large_province_percentage(): void
    {
        config(['shipping.province_groups.large' => [$this->neighbor]]);

        // 500g neighbor base 50000; +5% = 2500 => 52500.
        $result = $this->calculator()->calculate($this->request($this->neighbor, 500));

        $this->assertSame(2500, $result->largeProvinceSurcharge);
        $this->assertSame(52500, $result->total);
    }

    /** @test */
    public function it_applies_the_fragile_percentage_reusing_the_standard_tariff(): void
    {
        // No fragile-specific tariff exists; the calculator reuses the standard bracket
        // and adds the fragile percentage. 500g same_province base 40000; +25% = 10000.
        $result = $this->calculator()->calculate($this->request($this->origin, 500, package: 'fragile'));

        $this->assertSame(40000, $result->basePrice);
        $this->assertSame(10000, $result->fragileSurcharge);
        $this->assertSame(50000, $result->total);
    }

    /** @test */
    public function it_throws_when_no_tariff_matches(): void
    {
        PostTariff::query()->delete();

        $this->expectException(ShippingTariffNotFoundException::class);

        $this->calculator()->calculate($this->request($this->origin, 500));
    }
}
