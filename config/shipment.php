<?php

declare(strict_types=1);

/**
 * Parse a comma-separated env value into a unique list of positive integer ids.
 * Blank, non-numeric, and zero entries are dropped, so a stray comma or a quoted
 * empty string yields [] (= "no restriction") rather than an id of 0 that could
 * never match. Ints are required: the eligibility check compares strictly.
 */
$idList = static fn (string $key): array => array_values(array_unique(array_filter(
    array_map('intval', array_map('trim', explode(',', (string) env($key, '')))),
    static fn (int $id): bool => $id > 0,
)));

return [
    /*
    |--------------------------------------------------------------------------
    | Fixed fulfillment methods
    |--------------------------------------------------------------------------
    | The application supports exactly four fixed fulfillment methods. They are
    | intentionally config-backed (never a database table) so operators cannot
    | create, delete, rename, reorder, enable, disable, or reprice them through
    | the admin panel. Methods are identified by their stable string code — never
    | a database id.
    |
    | Prices are env-backed so a deployment can be repriced without a code change.
    | All are integer rials (the Cents Rule) — the (int) cast is required because
    | env() hands back strings, and a float price would violate financial integrity.
    | The value beside each env() call is the fallback when the key is absent.
    */
    'methods' => [
        'post_standard' => [
            'title' => 'Standard Post',
            'type' => 'postal',
            'price' => (int) env('SHIPMENT_POST_STANDARD_PRICE', 850_000),
            'requires_address' => true,
            'requires_delivery_slot' => false,
            'supports_tracking' => true,
            'estimated_min_days' => 3,
            'estimated_max_days' => 7,
            'enabled' => true,
        ],

        'post_express' => [
            'title' => 'Express Post',
            'type' => 'postal',
            'price' => (int) env('SHIPMENT_POST_EXPRESS_PRICE', 1_400_000),
            'requires_address' => true,
            'requires_delivery_slot' => false,
            'supports_tracking' => true,
            'estimated_min_days' => 1,
            'estimated_max_days' => 3,
            'enabled' => true,
        ],

        'local_delivery' => [
            'title' => 'Local Delivery',
            'type' => 'local_delivery',
            'price' => (int) env('SHIPMENT_LOCAL_DELIVERY_PRICE', 1_200_000),
            'requires_address' => true,
            'requires_delivery_slot' => true,
            'supports_tracking' => false,
            'estimated_min_days' => 0,
            'estimated_max_days' => 1,
            'enabled' => true,
        ],

        'in_person_pickup' => [
            'title' => 'Pickup from Store',
            'type' => 'pickup',
            // Free by default — the customer collects it themselves. Kept env-backed
            // for consistency, but raising it makes "pickup" a paid service.
            'price' => (int) env('SHIPMENT_PICKUP_PRICE', 0),
            'requires_address' => false,
            'requires_delivery_slot' => false,
            'supports_tracking' => false,
            'estimated_min_days' => null,
            'estimated_max_days' => null,
            'enabled' => true,

            'pickup_location' => [
                'title' => env('SHIPMENT_PICKUP_TITLE', 'Main Store'),
                'address' => env('SHIPMENT_PICKUP_ADDRESS'),
                'phone' => env('SHIPMENT_PICKUP_PHONE'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local-delivery service area
    |--------------------------------------------------------------------------
    | The region the store delivers to itself. Set city ids alone (the common
    | case), province ids alone, or both — an address matches if EITHER list
    | contains it, so provinces act as a broad zone and cities add exceptions
    | outside it.
    |
    | Leaving BOTH empty means "no service area configured": local delivery is
    | then offered everywhere and the postal exclusion below is inert. That is
    | the safe default — it is also why the exclusion cannot simply be "postal
    | is off wherever local delivery is on".
    |
    | Inside the service area the store delivers itself, so the two postal
    | methods are withdrawn: they are reported unavailable by
    | GET /shipment/methods and rejected with 422 at checkout. Pickup is never
    | affected — the customer may always collect in person.
    */
    'local_delivery' => [
        'province_ids' => $idList('SHIPMENT_LOCAL_DELIVERY_PROVINCE_IDS'),
        'city_ids' => $idList('SHIPMENT_LOCAL_DELIVERY_CITY_IDS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local-delivery session generation
    |--------------------------------------------------------------------------
    */
    'delivery' => [
        'slot_duration_minutes' => (int) env('SHIPMENT_SLOT_DURATION_MINUTES', 90),
        'default_capacity' => (int) env('SHIPMENT_SLOT_DEFAULT_CAPACITY', 3),
        'generation_days' => (int) env('SHIPMENT_SLOT_GENERATION_DAYS', 30),
        'booking_horizon_days' => (int) env('SHIPMENT_BOOKING_HORIZON_DAYS', 14),
        'minimum_lead_minutes' => (int) env('SHIPMENT_MINIMUM_LEAD_MINUTES', 60),
        'minimum_final_slot_minutes' => (int) env('SHIPMENT_MINIMUM_FINAL_SLOT_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pending-order hold lifetime
    |--------------------------------------------------------------------------
    | Kept in sync with the Order module's checkout TTL. A held delivery-slot
    | reservation expires alongside its pending order.
    */
    'pending_order_ttl_minutes' => (int) env('SHIPMENT_PENDING_ORDER_TTL_MINUTES', 15),
];
