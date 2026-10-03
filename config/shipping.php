<?php

declare(strict_types=1);

/**
 * Parse a comma-separated env value into a unique list of positive integer ids.
 * Mirrors the helper in config/shipment.php: blank/non-numeric/zero entries are
 * dropped so a stray comma yields [] rather than a province id of 0 that can never
 * match. Ints are required because membership is tested with strict in_array().
 */
$idList = static fn (string $key): array => array_values(array_unique(array_filter(
    array_map('intval', array_map('trim', explode(',', (string) env($key, '')))),
    static fn (int $id): bool => $id > 0,
)));

return [
    /*
    |--------------------------------------------------------------------------
    | Dynamic Post shipping calculation
    |--------------------------------------------------------------------------
    | Configuration for the Shipment module's Post tariff engine. The numeric
    | formula constants (surcharge percentages, island per-kg fee) do NOT live
    | here — they live in the `shipping_parameters` table so an operator can retune
    | pricing without a deploy. This file holds only:
    |   - the origin the store ships FROM (to classify same/neighbour/non-neighbour)
    |   - which province ids belong to each surcharge GROUP (membership, env-backed,
    |     consistent with the local-delivery service-area pattern in shipment.php)
    |   - the default package type and carrier code.
    |
    | MONEY UNIT: every amount the calculator produces or reads is in TOMANS
    | (integers — the Money Unit Rule). Conversion to rials happens only at an
    | external Post-API boundary, never here.
    */

    // The carrier these tariffs/parameters belong to. Kept configurable so a second
    // carrier can be added later without renaming rows.
    'carrier' => env('SHIPPING_POST_CARRIER', 'post'),

    // The province the store dispatches parcels from. Null disables dynamic Post
    // pricing entirely (the calculator cannot classify distance), so checkout falls
    // back to the static config price — the production-safe default until an operator
    // sets it.
    'origin_province_id' => ($origin = (int) env('SHIPPING_ORIGIN_PROVINCE_ID', 0)) > 0 ? $origin : null,

    // Default package type when a line/order does not declare one. There is no
    // per-product "fragile" flag today, so checkout always uses 'standard'; the
    // schema + calculator already support 'fragile' for when one is introduced.
    'default_package_type' => env('SHIPPING_DEFAULT_PACKAGE_TYPE', 'standard'),

    /*
    | Surcharge province groups (membership only — the percentages/fees are in
    | `shipping_parameters`). A destination province id may appear in more than one
    | group; each matching surcharge is then applied.
    |
    |   islands       -> flat per-kg island fee (shipping_parameters: island_extra_per_kg)
    |   large         -> large-province percentage (shipping_parameters: large_province_percentage)
    |   tehran_alborz -> capital-region percentage (shipping_parameters: tehran_percentage)
    */
    'province_groups' => [
        'islands' => $idList('SHIPPING_ISLAND_PROVINCE_IDS'),
        'large' => $idList('SHIPPING_LARGE_PROVINCE_IDS'),
        'tehran_alborz' => $idList('SHIPPING_TEHRAN_ALBORZ_PROVINCE_IDS'),
    ],
];
