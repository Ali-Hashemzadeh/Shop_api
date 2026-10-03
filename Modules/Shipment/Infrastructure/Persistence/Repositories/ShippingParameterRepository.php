<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Repositories;

use Modules\Shipment\Domain\Models\ShippingParameter;

/**
 * Reads tunable shipping formula constants for a carrier and casts each per its
 * stored `type`. All rows for a carrier are loaded once per instance so a single
 * calculation does not issue one query per parameter.
 */
class ShippingParameterRepository
{
    /** @var array<string, array<string, ShippingParameter>> carrier => (key => row) */
    private array $cache = [];

    /**
     * A parameter value cast to int (tomans, percentages, and plain integers are all
     * whole numbers). Returns $default when the row is absent.
     */
    public function integer(string $carrier, string $key, int $default = 0): int
    {
        $row = $this->row($carrier, $key);

        return $row === null ? $default : (int) $row->value;
    }

    /**
     * A parameter value as a raw string, or $default when absent.
     */
    public function string(string $carrier, string $key, string $default = ''): string
    {
        $row = $this->row($carrier, $key);

        return $row === null ? $default : (string) $row->value;
    }

    private function row(string $carrier, string $key): ?ShippingParameter
    {
        if (! isset($this->cache[$carrier])) {
            $this->cache[$carrier] = ShippingParameter::query()
                ->where('carrier', $carrier)
                ->get()
                ->keyBy('key')
                ->all();
        }

        return $this->cache[$carrier][$key] ?? null;
    }
}
