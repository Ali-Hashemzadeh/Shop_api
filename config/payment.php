<?php

return [
    'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'zarinpal'),

    'gateways' => [
        'zarinpal' => [
            'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),
            'sandbox' => env('ZARINPAL_SANDBOX', false),
        ],
    ],
];
