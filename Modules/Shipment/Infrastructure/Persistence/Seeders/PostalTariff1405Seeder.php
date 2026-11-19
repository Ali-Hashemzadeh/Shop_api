<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Modules\Shipment\Domain\Models\PostalTariff1405;
use Modules\Shipment\Domain\Postal\Post1405Catalog;
use Modules\Shipment\Infrastructure\Persistence\Post1405CatalogVerifier;

/**
 * Seeds the 66 explicit Iran Post 1405 base-rate rows (integer TOMANS) and then runs
 * the catalog verifier. PRODUCTION-SAFE:
 *   - insert-if-missing on the rate key (service_code, zone_code, weight_from,
 *     tariff_year); re-running never duplicates a bracket.
 *   - an existing row is NEVER overwritten: an operator-adjusted price is kept and the
 *     difference is REPORTED (console + log), not silently replaced.
 *   - a missing row is NOT inserted when it would overlap an existing hand-made
 *     bracket for the same service/zone/year — that conflict is reported instead.
 *   - nothing is deleted or truncated; the legacy post_tariffs table is not touched.
 *   - derived services (M-bag, periodicals) get no fabricated fixed-price rows.
 *
 * Run after PostalService1405Seeder (the rows reference service codes by FK).
 */
class PostalTariff1405Seeder extends Seeder
{
    public function run(): void
    {
        $inserted = 0;
        $kept = 0;
        $conflicts = [];

        foreach (Post1405Catalog::tariffs() as $expected) {
            $existing = PostalTariff1405::query()
                ->where('service_code', $expected['service_code'])
                ->where('zone_code', $expected['zone_code'])
                ->where('weight_from', $expected['weight_from'])
                ->where('tariff_year', $expected['tariff_year'])
                ->first();

            $key = Post1405CatalogVerifier::key($expected['service_code'], $expected['zone_code'], $expected['weight_from']);

            if ($existing !== null) {
                $kept++;

                foreach (['weight_to', 'base_price_toman', 'extra_per_started_kg_toman', 'extra_above_grams'] as $column) {
                    if ($existing->{$column} !== $expected[$column]) {
                        $conflicts[] = "{$key}.{$column}: kept database value ".var_export($existing->{$column}, true).', catalog has '.var_export($expected[$column], true);
                    }
                }

                continue;
            }

            if ($this->overlapsExisting($expected)) {
                $conflicts[] = "{$key}: not inserted — an existing bracket for this service/zone/year overlaps it";

                continue;
            }

            PostalTariff1405::query()->create($expected);
            $inserted++;
        }

        $this->command?->info("Postal 1405 tariffs: {$inserted} inserted, {$kept} already present.");

        foreach ($conflicts as $conflict) {
            $this->command?->warn("  Conflict — {$conflict}");
        }

        if ($conflicts !== []) {
            Log::warning('PostalTariff1405Seeder did not overwrite conflicting tariff rows.', ['conflicts' => $conflicts]);
        }

        $this->report(app(Post1405CatalogVerifier::class)->verify());
    }

    /** @param array<string, mixed> $row */
    private function overlapsExisting(array $row): bool
    {
        return PostalTariff1405::query()
            ->where('service_code', $row['service_code'])
            ->where('zone_code', $row['zone_code'])
            ->where('tariff_year', $row['tariff_year'])
            // existing.from <= new.to (or new is open-ended) AND (existing.to is open OR existing.to >= new.from)
            ->when($row['weight_to'] !== null, fn ($q) => $q->where('weight_from', '<=', $row['weight_to']))
            ->where(fn ($q) => $q->whereNull('weight_to')->orWhere('weight_to', '>=', $row['weight_from']))
            ->exists();
    }

    /** @param array<string, mixed> $report */
    private function report(array $report): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->line(sprintf('  Postal services: %d (expected %d)', $report['service_count'], $report['expected_service_count']));
        $this->command->line(sprintf('  Explicit tariff records: %d (expected %d)', $report['tariff_count'], $report['expected_tariff_count']));
        $this->command->line('  Negative prices: '.$report['negative_prices']);
        $this->command->line('  Overlapping brackets: '.count($report['overlapping_brackets']));
        $this->command->line('  Unrecognized method codes: '.count($report['unrecognized_service_codes']));
        $this->command->line('  Unrecognized zones: '.count($report['unrecognized_zones']));
        $this->command->line('  Unrecognized tariff years: '.count($report['unrecognized_tariff_years']));

        foreach ($report['flagged_rates'] as $flag) {
            $this->command->warn("  Needs admin verification — {$flag}");
        }

        $report['ok']
            ? $this->command->info('  1405 catalog verification passed.')
            : $this->command->error('  1405 catalog verification FAILED — run `php artisan shipping:post-1405:verify` for details.');
    }
}
