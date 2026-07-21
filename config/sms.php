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
                'shipment_preparing' => env('SMS_SMSIR_SHIPMENT_PREPARING_TEMPLATE_ID'),
                'shipment_sent' => env('SMS_SMSIR_SHIPMENT_SENT_TEMPLATE_ID'),
                'shipment_delivered' => env('SMS_SMSIR_SHIPMENT_DELIVERED_TEMPLATE_ID'),
            ],
        ],

        'log' => [],

        'fake' => [],
    ],
];
