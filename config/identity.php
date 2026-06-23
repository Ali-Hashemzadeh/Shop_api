<?php

return [
    'login_field' => env('AUTH_LOGIN_FIELD', 'both'),

    'otp' => [
        // Number of digits in a generated OTP code.
        'length' => (int) env('OTP_LENGTH', 5),

        // How long a generated code stays valid, in minutes.
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 2),
    ],

    'sms' => [
        'api_key' => env('SMSIR_API_KEY', ''),
        'template_id' => (int) env('SMSIR_TEMPLATE_ID', 0),
        // Must exactly match the placeholder name in your SMS.ir template (case-sensitive).
        'code_param' => env('SMSIR_CODE_PARAM', 'Code'),
    ],

    'address' => [
        'require_province' => env('ADDRESS_REQUIRE_PROVINCE', true),
        'require_city' => env('ADDRESS_REQUIRE_CITY', true),
        'require_postal_code' => env('ADDRESS_REQUIRE_POSTAL_CODE', false),
    ],
];
