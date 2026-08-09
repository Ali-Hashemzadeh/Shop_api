<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Active SMS provider
    |--------------------------------------------------------------------------
    | The rest of the application never names a provider — it talks to
    | SmsManagerInterface, which resolves whatever is configured here.
    |
    | Supported out of the box:
    |   smsir → live SMS.ir delivery
    |   log   → writes the message to the log (dev default, no network I/O)
    |   fake  → in-memory recorder used by the test suite
    */
    'default' => env('SMS_PROVIDER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Provider configuration
    |--------------------------------------------------------------------------
    | Template ids are provider-specific and therefore live under the provider
    | that owns them. The *keys* (payment_success, order_cancelled, …) are our
    | internal, provider-independent template names — they never change when a
    | provider is swapped. Business parameter names (OrderId, TrackingCode, …)
    | belong to the calling module and are never configured here.
    */
    'providers' => [
        'smsir' => [
            'api_key' => env('SMS_SMSIR_API_KEY', ''),
            'endpoint' => env('SMS_SMSIR_ENDPOINT', 'https://api.sms.ir/v1/send/verify'),

            'templates' => [
                'payment_success' => env('SMS_SMSIR_PAYMENT_SUCCESS_TEMPLATE_ID'),
                'order_cancelled' => env('SMS_SMSIR_ORDER_CANCELLED_TEMPLATE_ID'),
                'admin_order_paid' => env('SMS_SMSIR_ADMIN_ORDER_PAID_TEMPLATE_ID'),
                'shipment_preparing' => env('SMS_SMSIR_SHIPMENT_PREPARING_TEMPLATE_ID'),
                'shipment_ready_for_pickup' => env('SMS_SMSIR_SHIPMENT_READY_FOR_PICKUP_TEMPLATE_ID'),
                'shipment_handed_to_post' => env('SMS_SMSIR_SHIPMENT_HANDED_TO_POST_TEMPLATE_ID'),
                'shipment_out_for_delivery' => env('SMS_SMSIR_SHIPMENT_OUT_FOR_DELIVERY_TEMPLATE_ID'),
                'shipment_delivered' => env('SMS_SMSIR_SHIPMENT_DELIVERED_TEMPLATE_ID'),
                'shipment_assigned_delivery' => env('SMS_SMSIR_SHIPMENT_ASSIGNED_DELIVERY_TEMPLATE_ID'),

                // Legacy, no longer sent: `shipment_sent` covered both postal handoff
                // and local dispatch before they were split. Kept mapped so an
                // existing .env stays valid during the changeover; safe to delete
                // once no deployment references them.
                'shipment_sent' => env('SMS_SMSIR_SHIPMENT_SENT_TEMPLATE_ID'),
                'shipment_sent_delivery_code' => env('SMS_SMSIR_SHIPMENT_SENT_DELIVERY_CODE_TEMPLATE_ID'),
            ],
        ],

        'log' => [],

        'fake' => [],
    ],
];
