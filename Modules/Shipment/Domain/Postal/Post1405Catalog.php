<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Postal;

/**
 * The Iran Post 1405 tariff catalog: 21 service definitions and 66 explicit base-rate
 * rows, transcribed from the 1405 tariff PDF and converted from RIALS to TOMANS
 * (÷ 10). This is the single source the seeders insert and the verifier compares
 * the database against. Prices are published ceilings, not contracted rates.
 *
 * WEIGHT BOUNDARIES — every bracket is CLOSED on both ends in whole grams:
 *   "up to 500 g" = [1, 500], "over 500 g – 1 kg" = [501, 1000], and so on.
 * An open-ended bracket (weight_to = null) charges `extra_per_started_kg` for every
 * STARTED kilogram above `extra_above_grams`:
 *   extraKg = intdiv(max(0, weight - extra_above_grams) + 999, 1000)
 *
 * FLAGGED FOR ADMIN VERIFICATION: registered parcel, same province, 3–5 kg is printed
 * as 270,000 rials (27,000 tomans) — LOWER than the 2–3 kg bracket. It is reproduced
 * exactly; it is not corrected or interpolated. See Post1405CatalogVerifier.
 *
 * Specialised services (M-bag, periodicals) are DERIVED in the PDF (proportional /
 * 50 % of a referenced service). Their references cannot be resolved from the data
 * supplied, so they have no fixed-price rows and are never quotable.
 */
final class Post1405Catalog
{
    public const TARIFF_YEAR = 1405;

    /** Content kinds: what the shipment physically is. The shop ships `goods`. */
    public const CONTENT_DOCUMENT = 'document';

    public const CONTENT_GOODS = 'goods';

    public const CONTENT_PRINTED_MATTER = 'printed_matter';

    public const CONTENT_PERIODICAL = 'periodical';

    /** Same-province 3–5 kg registered-parcel rate printed below the previous bracket. */
    public const FLAGGED_RATES = [
        ['service_code' => 'post_registered_parcel', 'zone_code' => 'same_province', 'weight_from' => 3001,
            'note' => 'PDF prints 27,000 tomans for 3–5 kg same-province, below the 32,000 2–3 kg bracket. Reproduced verbatim — verify with Iran Post.'],
    ];

    /**
     * M-bag extra-kg rates published on PDF page 2 (tomans per started kg above 2 kg),
     * by distance zone. Kept for reference only: the ≤ 2 kg base is a proportional
     * registered-service calculation the supplied data cannot resolve, so the services
     * stay unquotable. Never used to fabricate a price.
     */
    public const MBAG_EXTRA_PER_KG_ABOVE_2KG = [
        'post_mbag_unregistered' => ['same_province' => 5000, 'neighbor' => 7500, 'non_neighbor' => 8500],
        'post_mbag_registered' => ['same_province' => 14000, 'neighbor' => 19000, 'non_neighbor' => 23000],
    ];

    /** @var array<string, PostalServiceDefinition>|null */
    private static ?array $services = null;

    /**
     * All 21 definitions keyed by code, in display order.
     *
     * @return array<string, PostalServiceDefinition>
     */
    public static function services(): array
    {
        if (self::$services !== null) {
            return self::$services;
        }

        $d = PostalServiceDefinition::class;
        $np = PostalScope::NonPriority;
        $sp = PostalScope::Specialized;
        $ur = PostalScope::Urban;
        $ic = PostalScope::Intercity;

        $list = [
            // ── Non-priority (PDF p.2, Tables 1–2) ─────────────────────────────────
            new $d('post_economy_unregistered', 'پستنامه غیرثبتی (اسناد)', $np, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_DISTANCE_BRACKET, 'PDF p.2 T1-2', $d::POLICY_OPEN, false, maxWeightGrams: 2000),
            new $d('post_registered_document', 'پستنامه ثبتی پاکت (اسناد)', $np, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_DISTANCE_BRACKET, 'PDF p.2 T1-2', $d::POLICY_OPEN, false, maxWeightGrams: 500,
                electronicService: true, compensationKind: 'envelope'),
            new $d('post_registered_packet', 'پستنامه ثبتی پاکت جوف / بسته', $np, self::CONTENT_GOODS, 'packet',
                $d::RULE_DISTANCE_BRACKET, 'PDF p.2 T1-2', $d::POLICY_OPEN, false, maxWeightGrams: 2000,
                electronicService: true, compensationKind: 'parcel'),
            new $d('post_registered_parcel', 'امانت پستی ثبتی', $np, self::CONTENT_GOODS, 'parcel',
                $d::RULE_DISTANCE_BRACKET_EXTRA_KG, 'PDF p.2 T1-2', $d::POLICY_OPEN, true,
                electronicService: true, compensationKind: 'parcel'),

            // ── Specialised, derived (PDF p.2) ────────────────────────────────────
            new $d('post_mbag_unregistered', 'کیسه M غیرثبتی', $sp, self::CONTENT_PRINTED_MATTER, 'bag',
                $d::RULE_DERIVED, 'PDF p.2 special', $d::POLICY_DERIVED_UNRESOLVED, false),
            new $d('post_mbag_registered', 'کیسه M ثبتی', $sp, self::CONTENT_PRINTED_MATTER, 'bag',
                $d::RULE_DERIVED, 'PDF p.2 special', $d::POLICY_DERIVED_UNRESOLVED, false),
            new $d('post_periodical', 'مطبوعات', $sp, self::CONTENT_PERIODICAL, 'bundle',
                $d::RULE_DERIVED, 'PDF p.2 special', $d::POLICY_DERIVED_UNRESOLVED, false, maxWeightGrams: 2000),

            // ── Urban priority (PDF p.3, Table 3) ─────────────────────────────────
            new $d('post_urban_3h_envelope', 'شهری ۳ ساعته — پاکت', $ur, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_PRIORITY_FLAT, 'PDF p.3 T3', $d::POLICY_URBAN_COVERAGE, false, maxWeightGrams: 1000, coverageFamily: 'urban_3h'),
            new $d('post_urban_3h_parcel', 'شهری ۳ ساعته — بسته', $ur, self::CONTENT_GOODS, 'parcel',
                $d::RULE_PRIORITY_BASE_PLUS_KG, 'PDF p.3 T3', $d::POLICY_URBAN_COVERAGE, true, coverageFamily: 'urban_3h'),
            new $d('post_urban_sameday_envelope', 'شهری همان‌روز — پاکت', $ur, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_PRIORITY_FLAT, 'PDF p.3 T3', $d::POLICY_URBAN_COVERAGE, false, maxWeightGrams: 1000, coverageFamily: 'urban_sameday'),
            new $d('post_urban_sameday_parcel', 'شهری همان‌روز — بسته', $ur, self::CONTENT_GOODS, 'parcel',
                $d::RULE_PRIORITY_BASE_PLUS_KG, 'PDF p.3 T3', $d::POLICY_URBAN_COVERAGE, true, coverageFamily: 'urban_sameday'),
            new $d('post_urban_nextday_envelope', 'شهری روز بعد — پاکت', $ur, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_PRIORITY_FLAT, 'PDF p.3 T3', $d::POLICY_URBAN_COVERAGE, false, maxWeightGrams: 1000, coverageFamily: 'urban_nextday'),
            new $d('post_urban_nextday_parcel', 'شهری روز بعد — بسته', $ur, self::CONTENT_GOODS, 'parcel',
                $d::RULE_PRIORITY_BASE_PLUS_KG, 'PDF p.3 T3', $d::POLICY_URBAN_COVERAGE, true, coverageFamily: 'urban_nextday'),

            // ── Intercity priority (PDF p.3, Table 4) ─────────────────────────────
            new $d('post_intercity_sameday_envelope', 'بین‌شهری همان‌روز — پاکت', $ic, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_PRIORITY_FLAT, 'PDF p.3 T4', $d::POLICY_ROUTE_ALLOWLIST, false, maxWeightGrams: 1000, coverageFamily: 'intercity_sameday'),
            new $d('post_intercity_sameday_parcel', 'بین‌شهری همان‌روز — بسته', $ic, self::CONTENT_GOODS, 'parcel',
                $d::RULE_PRIORITY_BASE_PLUS_KG, 'PDF p.3 T4', $d::POLICY_ROUTE_ALLOWLIST, true,
                maxWeightGrams: 10000, maxCartonSize: 6, coverageFamily: 'intercity_sameday'),
            new $d('post_intercity_d1_envelope', 'ویژه D+1 — پاکت', $ic, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_PRIORITY_FLAT, 'PDF p.3 T4', $d::POLICY_ROUTE_ALLOWLIST, false, maxWeightGrams: 1000, coverageFamily: 'intercity_d1'),
            new $d('post_intercity_d1_parcel', 'ویژه D+1 — بسته', $ic, self::CONTENT_GOODS, 'parcel',
                $d::RULE_PRIORITY_BASE_PLUS_KG, 'PDF p.3 T4', $d::POLICY_ROUTE_ALLOWLIST, true, coverageFamily: 'intercity_d1'),
            new $d('post_intercity_d2_envelope', 'اکسپرس D+2 — پاکت', $ic, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_PRIORITY_FLAT, 'PDF p.3 T4', $d::POLICY_ROUTE_ALLOWLIST, false, maxWeightGrams: 1000, coverageFamily: 'intercity_d2'),
            new $d('post_intercity_d2_parcel', 'اکسپرس D+2 — بسته', $ic, self::CONTENT_GOODS, 'parcel',
                $d::RULE_PRIORITY_BASE_PLUS_KG, 'PDF p.3 T4', $d::POLICY_ROUTE_ALLOWLIST, true, coverageFamily: 'intercity_d2'),
            new $d('post_intercity_d3_envelope', 'پیشتاز D+3 — پاکت', $ic, self::CONTENT_DOCUMENT, 'envelope',
                $d::RULE_PRIORITY_FLAT, 'PDF p.3 T4', $d::POLICY_OPEN, false, maxWeightGrams: 500),
            new $d('post_intercity_d3_parcel', 'پیشتاز D+3 — بسته', $ic, self::CONTENT_GOODS, 'parcel',
                $d::RULE_PRIORITY_BASE_PLUS_KG, 'PDF p.3 T4', $d::POLICY_OPEN, true),
        ];

        $byCode = [];
        foreach ($list as $definition) {
            $byCode[$definition->code] = $definition;
        }

        return self::$services = $byCode;
    }

    public static function find(string $code): ?PostalServiceDefinition
    {
        return self::services()[$code] ?? null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::services());
    }

    /**
     * The 66 explicit tariff rows, in tomans.
     *
     * @return list<array{service_code: string, zone_code: string, weight_from: int, weight_to: ?int, base_price_toman: int, extra_per_started_kg_toman: int, extra_above_grams: ?int, tariff_year: int}>
     */
    public static function tariffs(): array
    {
        $rows = [];

        // [service, weight_from, weight_to, [base×3 zones], [extra×3 zones] | null, extra_above | null]
        $distance = [
            ['post_economy_unregistered', 1, 1000, [7500, 11500, 13000], null, null],
            ['post_economy_unregistered', 1001, 2000, [13500, 20000, 22500], null, null],
            ['post_registered_document', 1, 500, [15500, 20500, 22900], null, null],
            ['post_registered_packet', 501, 1000, [20500, 28500, 32000], null, null],
            ['post_registered_packet', 1001, 2000, [29000, 37000, 41200], null, null],
            ['post_registered_parcel', 2001, 3000, [32000, 38500, 44900], null, null],
            ['post_registered_parcel', 3001, 5000, [27000, 45500, 52500], null, null],
            // Above 5 kg: the 5 kg rate + each additional STARTED kg.
            ['post_registered_parcel', 5001, null, [27000, 45500, 52500], [7500, 9500, 10000], 5000],

            ['post_intercity_sameday_envelope', 1, 1000, [184000, 223000, 261000], null, null],
            ['post_intercity_sameday_parcel', 1, null, [212000, 257000, 287000], [15500, 18500, 21500], 1000],
            ['post_intercity_d1_envelope', 1, 1000, [83000, 103000, 136000], null, null],
            ['post_intercity_d1_parcel', 1, null, [93000, 108000, 153000], [15500, 16500, 20000], 1000],
            ['post_intercity_d2_envelope', 1, 1000, [79000, 89000, 99000], null, null],
            ['post_intercity_d2_parcel', 1, null, [90000, 100000, 110000], [14000, 17000, 19000], 1000],
            // The D+3 envelope is "up to 500 g" — it is never used for a parcel.
            ['post_intercity_d3_envelope', 1, 500, [70000, 80000, 90000], null, null],
            ['post_intercity_d3_parcel', 1, null, [80000, 90000, 100000], [12500, 15500, 17500], 1000],
        ];

        $urban = [
            ['post_urban_3h_envelope', 1, 1000, [103000, 83000, 73000], null, null],
            ['post_urban_3h_parcel', 1, null, [118000, 93000, 76000], [14000, 12000, 11000], 1000],
            ['post_urban_sameday_envelope', 1, 1000, [83000, 73000, 63000], null, null],
            ['post_urban_sameday_parcel', 1, null, [103000, 88000, 68000], [13500, 11500, 10500], 1000],
            ['post_urban_nextday_envelope', 1, 1000, [78000, 68000, 58000], null, null],
            ['post_urban_nextday_parcel', 1, null, [83000, 73000, 63000], [15000, 13500, 11500], 1000],
        ];

        foreach ([[$distance, PostalZone::distanceCodes()], [$urban, PostalZone::urbanCodes()]] as [$table, $zones]) {
            foreach ($table as [$service, $from, $to, $bases, $extras, $extraAbove]) {
                foreach ($zones as $i => $zone) {
                    $rows[] = [
                        'service_code' => $service,
                        'zone_code' => $zone,
                        'weight_from' => $from,
                        'weight_to' => $to,
                        'base_price_toman' => $bases[$i],
                        'extra_per_started_kg_toman' => $extras[$i] ?? 0,
                        'extra_above_grams' => $extraAbove,
                        'tariff_year' => self::TARIFF_YEAR,
                    ];
                }
            }
        }

        return $rows;
    }
}
