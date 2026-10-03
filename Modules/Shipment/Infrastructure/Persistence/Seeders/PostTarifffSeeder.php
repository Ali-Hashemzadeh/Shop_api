<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Shipment\Domain\Models\PostTariff;

/**
 * Seeds an illustrative Post tariff table (operators retune the figures). PRODUCTION-SAFE:
 *   - firstOrCreate keyed on (service_type, package_type, distance_type, weight_from),
 *     so a tuned price survives a re-seed and re-running never duplicates a bracket.
 *   - Additive only; nothing deleted or truncated.
 *
 * Brackets are half-open on weight: [weight_from, weight_to) with weight_to = null the
 * open-ended top bracket that carries the per-kg extra_weight_price. All prices TOMANS.
 */
class PostTarifffSeeder extends Seeder
{
    /**
     * service_type => distance_type => list of [weight_from, weight_to, base_price, extra_weight_price].
     *
     * @var array<string, array<string, list<array{0:int,1:?int,2:int,3:int}>>>
     */
    private const TARIFFS = [
        'post_standard' => [
            'same_province' => [
                [0, 1000, 40000, 0],
                [1000, 3000, 55000, 0],
                [3000, null, 70000, 15000],
            ],
            'neighbor' => [
                [0, 1000, 50000, 0],
                [1000, 3000, 70000, 0],
                [3000, null, 90000, 20000],
            ],
            'non_neighbor' => [
                [0, 1000, 65000, 0],
                [1000, 3000, 90000, 0],
                [3000, null, 120000, 30000],
            ],
        ],
        'post_express' => [
            'same_province' => [
                [0, 1000, 65000, 0],
                [1000, 3000, 90000, 0],
                [3000, null, 115000, 25000],
            ],
            'neighbor' => [
                [0, 1000, 80000, 0],
                [1000, 3000, 110000, 0],
                [3000, null, 145000, 32000],
            ],
            'non_neighbor' => [
                [0, 1000, 105000, 0],
                [1000, 3000, 145000, 0],
                [3000, null, 190000, 45000],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::TARIFFS as $serviceType => $byDistance) {
            foreach ($byDistance as $distanceType => $brackets) {
                foreach ($brackets as [$from, $to, $base, $extra]) {
                    PostTariff::firstOrCreate(
                        [
                            'service_type' => $serviceType,
                            'package_type' => 'standard',
                            'distance_type' => $distanceType,
                            'weight_from' => $from,
                        ],
                        [
                            'weight_to' => $to,
                            'base_price' => $base,
                            'extra_weight_price' => $extra,
                            'effective_from' => null,
                            'effective_until' => null,
                        ],
                    );
                }
            }
        }
    }
}
