<?php

declare(strict_types=1);

/**
 * Parse a comma-separated env value into a unique list of positive integer ids.
 * Mirrors the helper in config/shipment.php: blank/non-numeric/zero entries are
 * dropped so a stray comma yields [] rather than an id of 0 that can never match.
 * Ints are required because membership is tested with strict in_array().
 */
$idList = static fn (string $key): array => array_values(array_unique(array_filter(
    array_map('intval', array_map('trim', explode(',', (string) env($key, '')))),
    static fn (int $id): bool => $id > 0,
)));

$positiveIntOrNull = static fn (string $key, ?int $default = null): ?int => ($value = (int) env($key, $default ?? 0)) > 0 ? $value : null;

$flag = static fn (string $key, bool $default): bool => filter_var(env($key, $default), FILTER_VALIDATE_BOOL);

/**
 * A strictly positive whole-number amount, or null. Unlike a bare (int) cast it never
 * truncates a malformed value ("13,000" or "130000.5" → null, not 13 / 130000), so a
 * typo disables the feature that needs the amount instead of charging a wrong price.
 */
$strictPositiveInt = static function (string $key, int $default): ?int {
    $value = env($key, $default);

    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    $value = trim((string) $value);

    return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
};

return [
    /*
    |--------------------------------------------------------------------------
    | Iran Post 1405 shipping engine
    |--------------------------------------------------------------------------
    | Configuration for the Shipment module's postal pricing. Tariff RATES do not
    | live here — they live in `postal_tariffs_1405` (seeded from Post1405Catalog).
    | The surcharge PERCENTAGES and the island per-kg fee live in the
    | `shipping_parameters` table so an operator retunes them with a row edit.
    | This file holds operational policy only: origin, toggles, geography lists,
    | route coverage, and the shop's own fees.
    |
    | MONEY UNIT: every amount is integer TOMANS (Money Unit Rule). The PDF prints
    | RIALS; all figures here and in the catalog are already ÷ 10.
    |
    | All geographic ids are this application's provinces.id / cities.id values
    | (verified against the production export of 2026-11-18), NOT government codes.
    | Run `php artisan shipping:post-1405:verify` after deploying to re-check them.
    */

    // The carrier the shipping_parameters rows belong to.
    'carrier' => env('SHIPPING_POST_CARRIER', 'post'),

    // Where parcels are dispatched FROM (Qom). Both are required: without them every
    // postal method is unavailable — there is no static-price fallback.
    'origin_province_id' => $positiveIntOrNull('SHIPPING_ORIGIN_PROVINCE_ID', 19),
    'origin_city_id' => $positiveIntOrNull('SHIPPING_ORIGIN_CITY_ID', 599),

    // `standard` or `fragile`. There is no per-product fragile flag, so this is the
    // store-wide classification; `fragile` adds fragile_percentage (PDF p.4 T5 r1).
    'default_package_type' => env('SHIPPING_DEFAULT_PACKAGE_TYPE', 'standard'),

    /*
    |--------------------------------------------------------------------------
    | Normal Post (پست عادی) — fixed-price fallback for carts with a missing weight
    |--------------------------------------------------------------------------
    | The 1405 engine cannot price a parcel when any item has no positive shipping
    | weight (and it never guesses one). Instead of blocking checkout, such a cart is
    | offered exactly one postal option — the `post_standard` method — at this flat
    | price, and every weight-based 1405 method is withdrawn for it. A cart whose
    | weights are all valid never sees `post_standard`, and checkout rejects it.
    |
    | `price_toman` is integer TOMANS (the unit of orders.shipping_cost) and is the
    | FINAL customer charge: no tariff, no geographic surcharge, no 10 % postal tax
    | and no packaging fee is added on top. 130000 = 130,000 tomans. It is a
    | shop-defined price, not Iran Post's published normal-post tariff.
    |
    | A blank, zero, negative or malformed price disables the fallback (fail-closed);
    | it then behaves as before — a per-item weight 422 at checkout.
    */
    'missing_weight_fallback' => [
        'enabled' => $flag('SHIPPING_MISSING_WEIGHT_FALLBACK_ENABLED', true),
        'price_toman' => $strictPositiveInt('SHIPMENT_POST_STANDARD_PRICE', 130000),
    ],

    'post_1405' => [
        'tariff_year' => 1405,

        // What the shop ships. Services whose content kind differs (documents,
        // printed matter, periodicals) never quote, even when enabled — a physical
        // parcel is never reinterpreted as an envelope.
        'content_kind' => 'goods',

        // Shop packaging: charged ONCE per postal order, never per item/kg, never
        // taxed, never on local delivery or pickup.
        'packaging_price_toman' => (int) env('SHIPPING_PACKAGING_PRICE_TOMAN', 50000),

        // Postal tax. Business requirement: exactly 10 %, floored to whole tomans.
        'postal_tax_percent' => (int) env('SHIPPING_POSTAL_TAX_PERCENT', 10),

        // Components the postal tax is computed on (shop policy, not a PDF rule).
        // Remove a key to exempt that component without changing the percentage.
        // `packaging` is intentionally absent and is ignored if added.
        'postal_tax_components' => [
            'postal_service_charge',
            'delivery_surcharge',
            'large_province_surcharge',
            'island_surcharge',
            'fragile_surcharge',
            'electronic_service_charge',
            'compensation',
        ],

        // PDF p.2 T1 row 14: electronic identification + SMS for registered
        // non-priority items (rows 3–7). Priority services already include it.
        'electronic_service' => [
            'enabled' => $flag('SHIPPING_POST_ELECTRONIC_SERVICE_ENABLED', true),
            'price_toman' => (int) env('SHIPPING_POST_ELECTRONIC_SERVICE_TOMAN', 7700),
        ],

        // PDF p.6 T7 published CEILINGS for compulsory compensation without declared
        // value. Off until the business confirms the actual applicable fee.
        'compensation' => [
            'enabled' => $flag('SHIPPING_POST_COMPENSATION_ENABLED', false),
            'envelope_toman' => (int) env('SHIPPING_POST_COMPENSATION_ENVELOPE_TOMAN', 6000),
            'parcel_toman' => (int) env('SHIPPING_POST_COMPENSATION_PARCEL_TOMAN', 10000),
        ],

        // The carton number the shop ships in. Needed only by intercity same-day
        // (max carton size 6). Unknown (blank) keeps that service unavailable.
        'carton_size' => $positiveIntOrNull('SHIPPING_POST_CARTON_SIZE'),

        /*
        | One toggle per method. `true` only PERMITS a method to be evaluated: it
        | is offered only when content, weight, geography, route coverage and tariff
        | all resolve. Every envelope/document/specialised service is off by default.
        */
        'services' => [
            'post_economy_unregistered' => $flag('SHIPPING_ENABLE_POST_ECONOMY_UNREGISTERED', false),
            'post_registered_document' => $flag('SHIPPING_ENABLE_POST_REGISTERED_DOCUMENT', false),
            'post_registered_packet' => $flag('SHIPPING_ENABLE_POST_REGISTERED_PACKET', false),
            'post_registered_parcel' => $flag('SHIPPING_ENABLE_POST_REGISTERED_PARCEL', true),

            'post_mbag_unregistered' => $flag('SHIPPING_ENABLE_POST_MBAG_UNREGISTERED', false),
            'post_mbag_registered' => $flag('SHIPPING_ENABLE_POST_MBAG_REGISTERED', false),
            'post_periodical' => $flag('SHIPPING_ENABLE_POST_PERIODICAL', false),

            'post_urban_3h_envelope' => $flag('SHIPPING_ENABLE_POST_URBAN_3H_ENVELOPE', false),
            'post_urban_3h_parcel' => $flag('SHIPPING_ENABLE_POST_URBAN_3H_PARCEL', true),
            'post_urban_sameday_envelope' => $flag('SHIPPING_ENABLE_POST_URBAN_SAMEDAY_ENVELOPE', false),
            'post_urban_sameday_parcel' => $flag('SHIPPING_ENABLE_POST_URBAN_SAMEDAY_PARCEL', true),
            'post_urban_nextday_envelope' => $flag('SHIPPING_ENABLE_POST_URBAN_NEXTDAY_ENVELOPE', false),
            'post_urban_nextday_parcel' => $flag('SHIPPING_ENABLE_POST_URBAN_NEXTDAY_PARCEL', true),

            'post_intercity_sameday_envelope' => $flag('SHIPPING_ENABLE_POST_INTERCITY_SAMEDAY_ENVELOPE', false),
            'post_intercity_sameday_parcel' => $flag('SHIPPING_ENABLE_POST_INTERCITY_SAMEDAY_PARCEL', true),
            'post_intercity_d1_envelope' => $flag('SHIPPING_ENABLE_POST_INTERCITY_D1_ENVELOPE', false),
            'post_intercity_d1_parcel' => $flag('SHIPPING_ENABLE_POST_INTERCITY_D1_PARCEL', true),
            'post_intercity_d2_envelope' => $flag('SHIPPING_ENABLE_POST_INTERCITY_D2_ENVELOPE', false),
            'post_intercity_d2_parcel' => $flag('SHIPPING_ENABLE_POST_INTERCITY_D2_PARCEL', true),
            'post_intercity_d3_envelope' => $flag('SHIPPING_ENABLE_POST_INTERCITY_D3_ENVELOPE', false),
            'post_intercity_d3_parcel' => $flag('SHIPPING_ENABLE_POST_INTERCITY_D3_PARCEL', true),
        ],

        /*
        | Operational route coverage. A published tariff is NOT proof a service runs
        | on a route, so services with a coverage family are offered only to the
        | destinations listed here (empty = nowhere). Origin is always the configured
        | origin. Fill these from Iran Post's confirmed coverage — never guess.
        |
        |   urban_*      : city ids where the urban service runs (destination must be
        |                  the origin city AND listed here)
        |   intercity_*  : destination province ids and/or city ids served from origin
        */
        'coverage' => [
            'urban_3h' => ['city_ids' => $idList('SHIPPING_POST_URBAN_3H_CITY_IDS')],
            'urban_sameday' => ['city_ids' => $idList('SHIPPING_POST_URBAN_SAMEDAY_CITY_IDS')],
            'urban_nextday' => ['city_ids' => $idList('SHIPPING_POST_URBAN_NEXTDAY_CITY_IDS')],
            'intercity_sameday' => [
                'province_ids' => $idList('SHIPPING_POST_INTERCITY_SAMEDAY_PROVINCE_IDS'),
                'city_ids' => $idList('SHIPPING_POST_INTERCITY_SAMEDAY_CITY_IDS'),
            ],
            'intercity_d1' => [
                'province_ids' => $idList('SHIPPING_POST_INTERCITY_D1_PROVINCE_IDS'),
                'city_ids' => $idList('SHIPPING_POST_INTERCITY_D1_CITY_IDS'),
            ],
            'intercity_d2' => [
                'province_ids' => $idList('SHIPPING_POST_INTERCITY_D2_PROVINCE_IDS'),
                'city_ids' => $idList('SHIPPING_POST_INTERCITY_D2_CITY_IDS'),
            ],
        ],

        /*
        | Urban RATE zones (PDF Table 3). Separate from the 15 % metropolitan
        | surcharge list below. A city in no list — or in more than one — cannot be
        | classified, so urban services are unavailable there.
        */
        'urban_zones' => [
            'metropolis' => $idList('SHIPPING_POST_URBAN_METROPOLIS_CITY_IDS'),
            'province_center' => $idList('SHIPPING_POST_URBAN_PROVINCE_CENTER_CITY_IDS'),
            'other_city' => $idList('SHIPPING_POST_URBAN_OTHER_CITY_IDS'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geographic surcharges (PDF p.6 rows 24–25)
    |--------------------------------------------------------------------------
    | Membership only; the percentages are shipping_parameters rows
    | (tehran_percentage, metropolitan_percentage, large_province_percentage,
    | island_extra_per_kg). Every percentage is applied to the postal service charge
    | (base + extra weight) — never to the island fee, packaging, or each other.
    */
    'surcharges' => [
        // 20 % anywhere in these provinces: 8 = تهران, 5 = البرز.
        'tehran_alborz_province_ids' => [8, 5],

        // 15 % for these cities — the shop's PROPOSED metropolitan list (the PDF
        // does not enumerate them). Never applied together with the 20 % rule.
        'metropolitan_enabled' => $flag('SHIPPING_METROPOLITAN_ENABLED', true),
        'metropolitan_city_ids' => [
            347, // مشهد
            115, // اصفهان
            530, // شیراز
            9,   // تبریز
            599, // قم
            382, // اهواز
            703, // کرمانشاه
        ],

        // 5 % — exactly the eight provinces named in PDF row 25. Khuzestan (13) is
        // NOT one of them. Independent of the metropolitan / Tehran surcharge.
        'large_province_enabled' => $flag('SHIPPING_LARGE_PROVINCE_ENABLED', true),
        'large_province_ids' => [
            21, // کرمان
            16, // سیستان و بلوچستان
            17, // فارس
            10, // خراسان جنوبی
            11, // خراسان رضوی
            4,  // اصفهان
            15, // سمنان
            31, // یزد
        ],

        // Island per-started-kg fee (25,500 tomans). No membership is assumed —
        // list only destinations Iran Post has confirmed. Empty = never applied.
        'island_province_ids' => $idList('SHIPPING_ISLAND_PROVINCE_IDS'),
        'island_city_ids' => $idList('SHIPPING_ISLAND_CITY_IDS'),
    ],
];
