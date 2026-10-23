<?php

// bayarkit — which gateway, and its keys. Every value comes from .env; nothing here is a secret.
return [
    // toyyibpay | chip
    'default' => env('BAYARKIT_GATEWAY', 'toyyibpay'),

    'timeout_ms' => (int) env('BAYARKIT_TIMEOUT_MS', 15000),

    'gateways' => [
        'toyyibpay' => [
            'secret_key' => env('TOYYIBPAY_SECRET_KEY'),
            'category_code' => env('TOYYIBPAY_CATEGORY_CODE'),
            // sandbox | production
            'environment' => env('TOYYIBPAY_ENV', 'sandbox'),
            // fpx (no card chargebacks) | card | both
            'channel' => env('TOYYIBPAY_CHANNEL', 'fpx'),
            // true = the customer pays the FPX fee; Confirm::payment then refuses (amount differs). Leave false.
            'charge_fee_to_customer' => (bool) env('TOYYIBPAY_CHARGE_FEE_TO_CUSTOMER', false),
            'base_url' => env('TOYYIBPAY_BASE_URL'),
        ],
        'chip' => [
            'secret_key' => env('CHIP_SECRET_KEY'),
            'brand_id' => env('CHIP_BRAND_ID'),
            // From `php artisan bayarkit:chip-public-key` (same secret key); without it every callback is refused.
            'public_key' => env('CHIP_PUBLIC_KEY'),
            // test | live — refuses a purchase whose is_test disagrees. Empty = no check.
            'environment' => env('CHIP_ENV'),
            // comma-separated, e.g. "fpx,duitnow_qr"; empty = every method enabled on the brand
            'payment_methods' => env('CHIP_PAYMENT_METHODS', ''),
            'base_url' => env('CHIP_BASE_URL'),
        ],
    ],
];
