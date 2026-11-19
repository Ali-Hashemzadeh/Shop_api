<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Shipment\Infrastructure\Persistence\Post1405CatalogVerifier;

/**
 * Read-only deployment check for the Iran Post 1405 engine:
 *   1. the persisted catalog (21 services / 66 tariffs, no overlaps, no unknown
 *      codes/zones/years, operator edits and flagged rates listed);
 *   2. every configured geographic id exists in THIS database, carries the expected
 *      name, and (for cities) belongs to the expected province.
 *
 * It never writes. Exit code 1 when anything that would mis-price an order is wrong.
 */
class ShippingPost1405VerifyCommand extends Command
{
    protected $signature = 'shipping:post-1405:verify';

    protected $description = 'Verify the Iran Post 1405 tariff catalog and the configured shipping geography ids (read-only).';

    /** Province ids the production export of 2026-11-18 maps to these names. */
    private const EXPECTED_PROVINCES = [
        19 => 'قم', 8 => 'تهران', 5 => 'البرز', 21 => 'کرمان', 16 => 'سیستان و بلوچستان', 17 => 'فارس',
        10 => 'خراسان جنوبی', 11 => 'خراسان رضوی', 4 => 'اصفهان', 15 => 'سمنان', 31 => 'یزد', 13 => 'خوزستان',
        28 => 'مرکزی', 1 => 'آذربایجان شرقی', 22 => 'کرمانشاه',
    ];

    /** City id => [name, province id] from the same export. */
    private const EXPECTED_CITIES = [
        599 => ['قم', 19], 219 => ['تهران', 8], 162 => ['کرج', 5], 347 => ['مشهد', 11], 115 => ['اصفهان', 4],
        530 => ['شیراز', 17], 9 => ['تبریز', 1], 382 => ['اهواز', 13], 703 => ['کرمانشاه', 22],
    ];

    public function handle(Post1405CatalogVerifier $verifier, IdentityManagerInterface $identity): int
    {
        $catalogOk = $this->reportCatalog($verifier->verify());
        $geographyOk = $this->reportGeography($identity);
        $this->reportPolicy();

        if ($catalogOk && $geographyOk) {
            $this->info('Iran Post 1405 verification passed.');

            return self::SUCCESS;
        }

        $this->error('Iran Post 1405 verification FAILED — postal methods may be unavailable or mis-priced until fixed.');

        return self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function reportCatalog(array $report): bool
    {
        $this->line('<comment>Tariff catalog</comment>');
        $this->table(['Check', 'Value', 'Expected'], [
            ['Postal services', $report['service_count'], $report['expected_service_count']],
            ['Explicit tariff records', $report['tariff_count'], $report['expected_tariff_count']],
            ['Negative prices', $report['negative_prices'], 0],
            ['Overlapping brackets', count($report['overlapping_brackets']), 0],
            ['Unrecognized method codes', count($report['unrecognized_service_codes']), 0],
            ['Unrecognized zones', count($report['unrecognized_zones']), 0],
            ['Unrecognized tariff years', count($report['unrecognized_tariff_years']), 0],
            ['Fixed rows for derived services', $report['derived_service_rows'], 0],
        ]);

        foreach (['overlapping_brackets', 'unrecognized_service_codes', 'unrecognized_zones', 'unrecognized_tariff_years', 'missing_services', 'missing_tariffs'] as $key) {
            foreach ($report[$key] as $item) {
                $this->error("  {$key}: {$item}");
            }
        }

        foreach ($report['differences'] as $difference) {
            $this->warn("  Differs from catalog (kept, review): {$difference}");
        }

        foreach ($report['flagged_rates'] as $flag) {
            $this->warn("  Needs admin verification: {$flag}");
        }

        return (bool) $report['ok'];
    }

    private function reportGeography(IdentityManagerInterface $identity): bool
    {
        $this->line('<comment>Geography ids</comment>');

        $originProvince = config('shipping.origin_province_id');
        $originCity = config('shipping.origin_city_id');
        $surcharges = (array) config('shipping.surcharges', []);
        $post = (array) config('shipping.post_1405', []);

        $provinceIds = array_values(array_unique(array_filter(array_merge(
            [$originProvince],
            (array) ($surcharges['tehran_alborz_province_ids'] ?? []),
            (array) ($surcharges['large_province_ids'] ?? []),
            (array) ($surcharges['island_province_ids'] ?? []),
            ...array_values(array_map(fn ($c) => (array) ($c['province_ids'] ?? []), (array) ($post['coverage'] ?? []))),
        ))));

        $cityIds = array_values(array_unique(array_filter(array_merge(
            [$originCity],
            (array) ($surcharges['metropolitan_city_ids'] ?? []),
            (array) ($surcharges['island_city_ids'] ?? []),
            ...array_values(array_map(fn ($c) => (array) ($c['city_ids'] ?? []), (array) ($post['coverage'] ?? []))),
            ...array_values(array_map(fn ($ids) => (array) $ids, (array) ($post['urban_zones'] ?? []))),
        ))));

        $found = $identity->getLocationReferences($provinceIds, $cityIds);
        $ok = true;
        $rows = [];

        if ($originProvince === null || $originCity === null) {
            $this->error('  Origin province/city is not configured (SHIPPING_ORIGIN_PROVINCE_ID / SHIPPING_ORIGIN_CITY_ID): every postal method is unavailable.');
            $ok = false;
        }

        foreach ($provinceIds as $id) {
            $name = $found['provinces'][$id] ?? null;
            $expected = self::EXPECTED_PROVINCES[$id] ?? null;
            $status = $name === null ? 'MISSING' : ($expected !== null && $this->normalize($name) !== $this->normalize($expected) ? 'NAME DIFFERS' : 'ok');
            $ok = $ok && $name !== null;
            $rows[] = ['province', $id, $name ?? '—', $expected ?? '(not in export list)', '', $status];
        }

        foreach ($cityIds as $id) {
            $city = $found['cities'][$id] ?? null;
            [$expectedName, $expectedProvince] = self::EXPECTED_CITIES[$id] ?? [null, null];
            $status = 'ok';

            if ($city === null) {
                $status = 'MISSING';
                $ok = false;
            } elseif ($expectedProvince !== null && $city['province_id'] !== $expectedProvince) {
                $status = 'WRONG PROVINCE';
                $ok = false;
            } elseif ($expectedName !== null && $this->normalize($city['name']) !== $this->normalize($expectedName)) {
                $status = 'NAME DIFFERS';
            }

            $rows[] = ['city', $id, $city['name'] ?? '—', $expectedName ?? '(not in export list)', $city['province_id'] ?? '—', $status];
        }

        if ($originCity !== null && isset($found['cities'][$originCity]) && $found['cities'][$originCity]['province_id'] !== $originProvince) {
            $this->error("  Origin city {$originCity} does not belong to origin province {$originProvince}.");
            $ok = false;
        }

        $this->table(['Kind', 'Id', 'Name in DB', 'Expected', 'Province', 'Status'], $rows);

        return $ok;
    }

    private function reportPolicy(): void
    {
        $this->line('<comment>Pricing policy</comment>');

        $enabled = array_keys(array_filter((array) config('shipping.post_1405.services', [])));

        $this->table(['Setting', 'Value'], [
            ['Postal tax %', config('shipping.post_1405.postal_tax_percent')],
            ['Packaging (toman / postal order)', config('shipping.post_1405.packaging_price_toman')],
            ['Metropolitan 15 % enabled', var_export((bool) config('shipping.surcharges.metropolitan_enabled'), true)],
            ['Large-province 5 % enabled', var_export((bool) config('shipping.surcharges.large_province_enabled'), true)],
            ['Electronic service (toman)', config('shipping.post_1405.electronic_service.enabled') ? config('shipping.post_1405.electronic_service.price_toman') : 'off'],
            ['Compensation', config('shipping.post_1405.compensation.enabled') ? 'on' : 'off'],
            ['Carton size', config('shipping.post_1405.carton_size') ?? 'unknown'],
            ['Enabled methods', implode(', ', $enabled)],
        ]);

        if ((int) config('shipping.post_1405.postal_tax_percent') !== 10) {
            $this->warn('  Postal tax is not 10 % — the business requirement is exactly 10 %.');
        }
    }

    /** Compare Persian names independent of Arabic/Persian letter variants and spacing. */
    private function normalize(string $name): string
    {
        return preg_replace('/[\s\x{200C}]+/u', '', strtr($name, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی'])) ?? $name;
    }
}
