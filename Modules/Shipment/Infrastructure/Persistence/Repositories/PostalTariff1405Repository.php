<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Repositories;

use Modules\Shipment\Domain\Models\PostalTariff1405;

/**
 * Finds the single 1405 tariff bracket for (service, zone, weight, year).
 *
 * The whole year's catalog (66 rows) is loaded once per instance, so pricing every
 * enabled postal method on the method list costs one query, not one per method.
 * Brackets are CLOSED: weight_from <= weight <= weight_to (NULL = open-ended).
 *
 * Fail-closed on ambiguity: if two rows match (an overlapping hand-edited bracket),
 * no tariff is returned rather than silently picking one.
 */
class PostalTariff1405Repository
{
    /** @var array<int, array<string, list<PostalTariff1405>>> year => "service|zone" => rows */
    private array $cache = [];

    public function findBracket(string $serviceCode, string $zoneCode, int $weightGrams, int $tariffYear): ?PostalTariff1405
    {
        $rows = $this->rowsFor($tariffYear)["{$serviceCode}|{$zoneCode}"] ?? [];

        $matches = array_values(array_filter(
            $rows,
            static fn (PostalTariff1405 $row): bool => $row->weight_from <= $weightGrams
                && ($row->weight_to === null || $weightGrams <= $row->weight_to),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<string, list<PostalTariff1405>> */
    private function rowsFor(int $tariffYear): array
    {
        if (! isset($this->cache[$tariffYear])) {
            $grouped = [];

            foreach (PostalTariff1405::query()->where('tariff_year', $tariffYear)->get() as $row) {
                $grouped["{$row->service_code}|{$row->zone_code}"][] = $row;
            }

            $this->cache[$tariffYear] = $grouped;
        }

        return $this->cache[$tariffYear];
    }
}
