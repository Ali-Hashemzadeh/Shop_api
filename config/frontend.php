<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Frontend application base URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the customer-facing frontend (SPA / storefront). The backend
    | never redirects the payment gateway here — it only builds navigation
    | links on the backend-rendered payment result page from this value.
    | Trailing slashes are normalized so composed URLs never become malformed.
    | Falls back to APP_URL when FRONTEND_URL is not configured.
    |
    */
    'url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Frontend order path
    |--------------------------------------------------------------------------
    |
    | Path segment (relative to the frontend URL) where a customer views a
    | single order / tracking page. The verified order id is appended, giving
    | {FRONTEND_URL}/{order_path}/{orderId}.
    |
    | Assumption: the frontend exposes orders at {FRONTEND_URL}/orders/{id}.
    | Adjust FRONTEND_ORDER_PATH if the frontend route differs.
    |
    */
    'order_path' => trim((string) env('FRONTEND_ORDER_PATH', 'orders'), '/'),
];
