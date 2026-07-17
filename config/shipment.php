<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Fixed fulfillment methods
    |--------------------------------------------------------------------------
    | The application supports exactly four fixed fulfillment methods. They are
    | intentionally config-backed (never a database table) so operators cannot
    | create, delete, rename, reorder, enable, disable, or reprice them through
    | the admin panel. Methods are identified by their stable string code — never
    | a database id. All prices are integer rials (the Cents Rule).
    */
    'methods' => [
        'post_standard' => [
            'title' => 'Standard Post',
            'type' => 'postal',
            'price' => 850_000,
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
            'price' => 1_400_000,
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
            'price' => 1_200_000,
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
            'price' => 0,
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
