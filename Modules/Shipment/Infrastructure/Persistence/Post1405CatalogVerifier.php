<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence;

use Modules\Shipment\Domain\Models\PostalService1405;
use Modules\Shipment\Domain\Models\PostalTariff1405;
use Modules\Shipment\Domain\Postal\Post1405Catalog;
use Modules\Shipment\Domain\Postal\PostalScope;

/**
 * Read-only integrity check of the persisted 1405 catalog against Post1405Catalog.
 * Used by both 1405 seeders (printed after seeding) and the
 * `shipping:post-1405:verify` command. It never writes.
 *
 * Errors (any of them makes `ok` false):
 *   counts ≠ 21 / 66, negative prices, overlapping brackets, unrecognised service
 *   codes / zones / tariff years, fixed-price rows for derived services.
 * Informational (reported, never "fixed"):
 *   `differences` — rows whose values differ from the catalog (operator edits),
 *   `flagged_rates` — published rates that need administrator verification.
 */
class Post1405CatalogVerifier
{
    /**
     * @return array{ok: bool, service_count: int, expected_service_count: int, tariff_count: int, expected_tariff_count: int, negative_prices: int, overlapping_brackets: list<string>, unrecognized_service_codes: list<string>, unrecognized_zones: list<string>, unrecognized_tariff_years: list<int>, missing_services: list<string>, missing_tariffs: list<string>, derived_service_rows: int, differences: list<string>, flagged_rates: list<string>}
     */
    public function verify(): array
    {
        $year = Post1405Catalog::TARIFF_YEAR;
        $catalog = Post1405Catalog::services();

        $services = PostalService1405::query()->get()->keyBy('code');
        $allTariffs = PostalTariff1405::query()->orderBy('service_code')->orderBy('zone_code')->orderBy('weight_from')->get();
        $tariffs = $allTariffs->where('tariff_year', $year);

        $unrecognizedCodes = $services->keys()
            ->merge($allTariffs->pluck('service_code'))
            ->unique()
            ->reject(fn ($code) => isset($catalog[$code]))
            ->values()->all();

        $unrecognizedYears = $allTariffs->pluck('tariff_year')
            ->merge($services->pluck('tariff_year'))
            ->map(fn ($y) => (int) $y)
            ->unique()
            ->reject(fn (int $y) => $y === $year)
            ->values()->all();

        $unrecognizedZones = [];
        $negative = 0;
        $derivedRows = 0;

        foreach ($tariffs as $row) {
            $definition = $catalog[$row->service_code] ?? null;
            $zones = $definition?->scope->zoneCodes() ?? array_merge(PostalScope::NonPriority->zoneCodes(), PostalScope::Urban->zoneCodes());

            if (! in_array($row->zone_code, $zones, true)) {
                $unrecognizedZones[] = "{$row->service_code}/{$row->zone_code}";
            }

            if ($definition?->isDerived()) {
                $derivedRows++;
            }

            foreach (['weight_from', 'weight_to', 'base_price_toman', 'extra_per_started_kg_toman', 'extra_above_grams'] as $column) {
                if ($row->{$column} !== null && (int) $row->{$column} < 0) {
                    $negative++;
                }
            }
        }

        $report = [
            'ok' => false, // computed below
            'service_count' => $services->count(),
            'expected_service_count' => count($catalog),
            'tariff_count' => $tariffs->count(),
            'expected_tariff_count' => count(Post1405Catalog::tariffs()),
            'negative_prices' => $negative,
            'overlapping_brackets' => $this->overlaps($tariffs->all()),
            'unrecognized_service_codes' => $unrecognizedCodes,
            'unrecognized_zones' => array_values(array_unique($unrecognizedZones)),
            'unrecognized_tariff_years' => $unrecognizedYears,
            'missing_services' => array_values(array_diff(array_keys($catalog), $services->keys()->all())),
            'missing_tariffs' => $this->missingTariffs($tariffs->all()),
            'derived_service_rows' => $derivedRows,
            'differences' => array_merge($this->serviceDifferences($services->all()), $this->tariffDifferences($tariffs->all())),
            'flagged_rates' => array_map(
                fn (array $flag): string => "{$flag['service_code']}/{$flag['zone_code']}/{$flag['weight_from']}g: {$flag['note']}",
                Post1405Catalog::FLAGGED_RATES,
            ),
        ];

        $report['ok'] = $report['service_count'] === $report['expected_service_count']
            && $report['tariff_count'] === $report['expected_tariff_count']
            && $report['negative_prices'] === 0
            && $report['overlapping_brackets'] === []
            && $report['unrecognized_service_codes'] === []
            && $report['unrecognized_zones'] === []
            && $report['unrecognized_tariff_years'] === []
            && $report['missing_services'] === []
            && $report['missing_tariffs'] === []
            && $report['derived_service_rows'] === 0;

        return $report;
    }

    /**
     * Closed brackets overlap when, within one (service, zone), a row starts at or
     * before the previous row's end (or the previous row is open-ended).
     *
     * @param  list<PostalTariff1405>  $rows
     * @return list<string>
     */
    private function overlaps(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups["{$row->service_code}/{$row->zone_code}"][] = $row;
        }

        $overlaps = [];
        foreach ($groups as $key => $group) {
            usort($group, fn (PostalTariff1405 $a, PostalTariff1405 $b): int => $a->weight_from <=> $b->weight_from);

            for ($i = 1, $n = count($group); $i < $n; $i++) {
                $previous = $group[$i - 1];
                if ($previous->weight_to === null || $group[$i]->weight_from <= $previous->weight_to) {
                    $overlaps[] = "{$key}: [{$previous->weight_from}–".($previous->weight_to ?? '∞')."] overlaps [{$group[$i]->weight_from}–".($group[$i]->weight_to ?? '∞').']';
                }
            }
        }

        return $overlaps;
    }

    /**
     * @param  list<PostalTariff1405>  $rows
     * @return list<string>
     */
    private function missingTariffs(array $rows): array
    {
        $present = [];
        foreach ($rows as $row) {
            $present[self::key($row->service_code, $row->zone_code, (int) $row->weight_from)] = true;
        }

        $missing = [];
        foreach (Post1405Catalog::tariffs() as $expected) {
            $key = self::key($expected['service_code'], $expected['zone_code'], $expected['weight_from']);
            if (! isset($present[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, PostalService1405>  $services
     * @return list<string>
     */
    private function serviceDifferences(array $services): array
    {
        $differences = [];

        foreach (Post1405Catalog::services() as $code => $definition) {
            $row = $services[$code] ?? null;
            if ($row === null) {
                continue;
            }

            foreach ($definition->toRow(Post1405Catalog::TARIFF_YEAR) as $column => $expected) {
                if ((string) $row->{$column} !== (string) $expected) {
                    $differences[] = "service {$code}.{$column}: database=".var_export($row->{$column}, true).' catalog='.var_export($expected, true);
                }
            }
        }

        return $differences;
    }

    /**
     * @param  list<PostalTariff1405>  $rows
     * @return list<string>
     */
    private function tariffDifferences(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[self::key($row->service_code, $row->zone_code, (int) $row->weight_from)] = $row;
        }

        $differences = [];
        foreach (Post1405Catalog::tariffs() as $expected) {
            $key = self::key($expected['service_code'], $expected['zone_code'], $expected['weight_from']);
            $row = $byKey[$key] ?? null;
            if ($row === null) {
                continue;
            }

            foreach (['weight_to', 'base_price_toman', 'extra_per_started_kg_toman', 'extra_above_grams'] as $column) {
                if ($row->{$column} !== $expected[$column]) {
                    $differences[] = "tariff {$key}.{$column}: database=".var_export($row->{$column}, true).' catalog='.var_export($expected[$column], true);
                }
            }
        }

        return $differences;
    }

    public static function key(string $service, string $zone, int $weightFrom): string
    {
        return "{$service}/{$zone}/{$weightFrom}";
    }
}
