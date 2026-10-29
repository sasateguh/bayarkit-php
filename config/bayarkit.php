<?php

// bayarkit — which gateway, and its keys. Every value comes from .env; nothing here is a secret.
return [
    // toyyibpay | chip | paddle | paypal
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
            // REQUIRED: test | live (match the key). A purchase from the other mode, or one that does not say, is refused.
            'environment' => env('CHIP_ENV'),
            // comma-separated, e.g. "fpx,duitnow_qr"; empty = every method enabled on the brand
            'payment_methods' => env('CHIP_PAYMENT_METHODS', ''),
            'base_url' => env('CHIP_BASE_URL'),
        ],
        'paddle' => [
            // pdl_sdbx_apikey_… (sandbox) or pdl_live_apikey_… (live): the key decides the host
            'api_key' => env('PADDLE_API_KEY'),
            // the notification destination's secret key (pdl_ntfset_…)
            'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
            // Paddle has no MYR: every payment is charged in this one currency (pass the same to CreatePaymentInput)
            'currency' => env('PADDLE_CURRENCY', 'USD'),
            // must be enabled on your Paddle account
            'tax_category' => env('PADDLE_TAX_CATEGORY', 'standard'),
            // empty = your default payment link (Paddle > Checkout settings)
            'checkout_url' => env('PADDLE_CHECKOUT_URL'),
            // maximum notification age in seconds
            'tolerance_seconds' => (int) env('PADDLE_TOLERANCE_SECONDS', 5),
            'base_url' => env('PADDLE_BASE_URL'),
        ],
        'paypal' => [
            // the REST app's credentials (sandbox and live apps have different ones)
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            // the id PayPal gave the webhook you added to this REST app
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            // sandbox | live
            'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'),
            // what Confirm expects when Expected names no currency (pass the same to CreatePaymentInput)
            'currency' => env('PAYPAL_CURRENCY', 'USD'),
            // shown on the PayPal page instead of your business name; empty = the account's name
            'brand_name' => env('PAYPAL_BRAND_NAME'),
            // NO_SHIPPING | GET_FROM_FILE
            'shipping_preference' => env('PAYPAL_SHIPPING_PREFERENCE', 'NO_SHIPPING'),
            'base_url' => env('PAYPAL_BASE_URL'),
        ],
    ],
];
