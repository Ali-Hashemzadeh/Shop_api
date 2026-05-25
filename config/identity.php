<?php

return [
    'login_field' => env('AUTH_LOGIN_FIELD', 'both'),

    'address' => [
        'require_province' => env('ADDRESS_REQUIRE_PROVINCE', true),
        'require_city' => env('ADDRESS_REQUIRE_CITY', true),
        'require_postal_code' => env('ADDRESS_REQUIRE_POSTAL_CODE', false),
    ],
];
