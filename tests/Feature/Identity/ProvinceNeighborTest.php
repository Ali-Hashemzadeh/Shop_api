<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Identity\Domain\Models\Province;
use Modules\Identity\Domain\Models\ProvinceNeighbor;
use Modules\Identity\Infrastructure\Persistence\Seeders\ProvinceNeighborSeeder;
use Tests\TestCase;

class ProvinceNeighborTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): IdentityManagerInterface
    {
        return app(IdentityManagerInterface::class);
    }

    /** @test */
    public function same_province_is_classified_as_same_province(): void
    {
        $tehran = Province::create(['name' => 'تهران']);

        $this->assertSame('same_province', $this->identity()->getProvinceDistanceType($tehran->id, $tehran->id));
    }

    /** @test */
    public function a_bordering_province_is_classified_as_neighbor_in_both_directions(): void
    {
        $tehran = Province::create(['name' => 'تهران']);
        $qom = Province::create(['name' => 'قم']);

        // Seeded one direction only — the classifier must still match the reverse.
        ProvinceNeighbor::create(['province_id' => $tehran->id, 'neighbor_province_id' => $qom->id]);

        $this->assertSame('neighbor', $this->identity()->getProvinceDistanceType($tehran->id, $qom->id));
        $this->assertSame('neighbor', $this->identity()->getProvinceDistanceType($qom->id, $tehran->id));
    }

    /** @test */
    public function a_non_bordering_province_is_classified_as_non_neighbor(): void
    {
        $tehran = Province::create(['name' => 'تهران']);
        $fars = Province::create(['name' => 'فارس']);

        $this->assertSame('non_neighbor', $this->identity()->getProvinceDistanceType($tehran->id, $fars->id));
    }

    /** @test */
    public function a_null_province_defaults_to_non_neighbor(): void
    {
        $tehran = Province::create(['name' => 'تهران']);

        $this->assertSame('non_neighbor', $this->identity()->getProvinceDistanceType(null, $tehran->id));
        $this->assertSame('non_neighbor', $this->identity()->getProvinceDistanceType($tehran->id, null));
        $this->assertSame('non_neighbor', $this->identity()->getProvinceDistanceType(null, null));
    }

    /** @test */
    public function seeder_creates_symmetric_neighbor_rows(): void
    {
        $tehran = Province::create(['name' => 'تهران']);
        $qom = Province::create(['name' => 'قم']);

        $this->seed(ProvinceNeighborSeeder::class);

        // Tehran and Qom border each other in the seeder's adjacency map, seeded both ways.
        $this->assertDatabaseHas('province_neighbors', [
            'province_id' => $tehran->id,
            'neighbor_province_id' => $qom->id,
        ]);
        $this->assertDatabaseHas('province_neighbors', [
            'province_id' => $qom->id,
            'neighbor_province_id' => $tehran->id,
        ]);
    }

    /** @test */
    public function seeder_is_idempotent_and_does_not_duplicate_neighbors(): void
    {
        Province::create(['name' => 'تهران']);
        Province::create(['name' => 'قم']);
        Province::create(['name' => 'البرز']);

        $this->seed(ProvinceNeighborSeeder::class);
        $countAfterFirst = ProvinceNeighbor::count();

        $this->seed(ProvinceNeighborSeeder::class);
        $countAfterSecond = ProvinceNeighbor::count();

        $this->assertGreaterThan(0, $countAfterFirst);
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    /** @test */
    public function seeder_skips_provinces_that_do_not_exist(): void
    {
        // Only one of the adjacency provinces exists — the seeder must not fail and must
        // create no rows (a lone province has no seeded neighbour present).
        Province::create(['name' => 'تهران']);

        $this->seed(ProvinceNeighborSeeder::class);

        $this->assertSame(0, ProvinceNeighbor::count());
    }
}
