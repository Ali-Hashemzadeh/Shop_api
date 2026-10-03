<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Shipment\Domain\Models\ShippingParameter;

/**
 * Seeds the tunable Post formula constants. PRODUCTION-SAFE:
 *   - firstOrCreate keyed on (carrier, key), so an operator's later edit to a value
 *     survives a re-seed (we never overwrite a tuned figure), and re-running never
 *     duplicates a row.
 *   - Additive only; nothing is deleted or truncated.
 *
 * MONEY UNIT: amount values are TOMANS; percentages are whole-number percents.
 */
class ShippingParameterSeeder extends Seeder
{
    /**
     * @var list<array{key: string, value: string, type: string, description: string}>
     */
    private const PARAMETERS = [
        [
            'key' => 'island_extra_per_kg',
            'value' => '255000',
            'type' => 'integer_toman',
            'description' => 'Flat surcharge (toman) per kilogram, rounded up, for island destinations.',
        ],
        [
            'key' => 'fragile_percentage',
            'value' => '25',
            'type' => 'percentage',
            'description' => 'Percentage added when the package is marked fragile.',
        ],
        [
            'key' => 'large_province_percentage',
            'value' => '5',
            'type' => 'percentage',
            'description' => 'Percentage added for large-province destinations.',
        ],
        [
            'key' => 'tehran_percentage',
            'value' => '20',
            'type' => 'percentage',
            'description' => 'Percentage added for Tehran/Alborz destinations.',
        ],
    ];

    public function run(): void
    {
        $carrier = (string) config('shipping.carrier', 'post');

        foreach (self::PARAMETERS as $parameter) {
            ShippingParameter::firstOrCreate(
                ['carrier' => $carrier, 'key' => $parameter['key']],
                [
                    'value' => $parameter['value'],
                    'type' => $parameter['type'],
                    'description' => $parameter['description'],
                ],
            );
        }
    }
}
