<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Modules\Shipment\Domain\Models\ShippingParameter;

/**
 * Seeds the tunable postal formula constants. PRODUCTION-SAFE:
 *   - insert-if-missing keyed on (carrier, key); re-running never duplicates a row.
 *   - an existing value is NEVER overwritten (an operator's edit survives). If it
 *     differs from the canonical 1405 value the difference is reported, not replaced.
 *   - nothing is deleted or truncated.
 *
 * MONEY UNIT: amounts are TOMANS; percentages are whole-number percents.
 * The island fee is 255,000 RIALS per started kg in the PDF = 25,500 TOMANS. (The
 * previous seeder wrote 255000 — a rial figure in a toman column; such a row is
 * reported by this seeder so an operator can correct it.)
 */
class ShippingParameterSeeder extends Seeder
{
    /**
     * @var list<array{key: string, value: string, type: string, description: string}>
     */
    private const PARAMETERS = [
        [
            'key' => 'island_extra_per_kg',
            'value' => '25500',
            'type' => 'integer_toman',
            'description' => 'Island surcharge (toman) per started kilogram — PDF 255,000 rials.',
        ],
        [
            'key' => 'fragile_percentage',
            'value' => '25',
            'type' => 'percentage',
            'description' => 'Percentage of the postal service charge for fragile/liquid shipments (PDF p.4 T5 r1).',
        ],
        [
            'key' => 'large_province_percentage',
            'value' => '5',
            'type' => 'percentage',
            'description' => 'Percentage of the postal service charge for the eight large provinces (PDF p.6 r25).',
        ],
        [
            'key' => 'tehran_percentage',
            'value' => '20',
            'type' => 'percentage',
            'description' => 'Percentage of the postal service charge for Tehran/Alborz destinations (PDF p.6 r24).',
        ],
        [
            'key' => 'metropolitan_percentage',
            'value' => '15',
            'type' => 'percentage',
            'description' => 'Percentage of the postal service charge for other metropolitan cities (PDF p.6 r24).',
        ],
    ];

    public function run(): void
    {
        $carrier = (string) config('shipping.carrier', 'post');
        $conflicts = [];

        foreach (self::PARAMETERS as $parameter) {
            $row = ShippingParameter::firstOrCreate(
                ['carrier' => $carrier, 'key' => $parameter['key']],
                [
                    'value' => $parameter['value'],
                    'type' => $parameter['type'],
                    'description' => $parameter['description'],
                ],
            );

            if (! $row->wasRecentlyCreated && trim((string) $row->value) !== $parameter['value']) {
                $conflicts[] = "{$carrier}.{$parameter['key']}: kept database value {$row->value}, canonical 1405 value is {$parameter['value']}";
            }
        }

        foreach ($conflicts as $conflict) {
            $this->command?->warn("  Not overwritten — {$conflict}");
        }

        if ($conflicts !== []) {
            Log::warning('ShippingParameterSeeder kept existing values that differ from the 1405 canonical values.', ['conflicts' => $conflicts]);
        }
    }
}
