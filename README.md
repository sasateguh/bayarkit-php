# bayarkit for PHP and Laravel

[Bahasa Melayu](README.ms.md)

The PHP twin of the TypeScript packages: the same interface, the same "paid only when the gateway says so" rule, and
tests against the **same fake gateways** (`bayarkit-fake`). Gateways: **toyyibPay** (measured on the sandbox) and
**CHIP** (from the official OpenAPI, not yet measured). Others are TypeScript-only for now.

Requires PHP 8.2+ with `curl`, `json` and `openssl`. No runtime dependencies; Laravel 11/12 support is optional.

> Not on Packagist yet (the repository is private until release). In a project on the same machine, use a path
> repository: `"repositories": [{"type": "path", "url": "../bayarkit/php"}]`, then `composer require bayarkit/bayarkit:@dev`.

## Laravel

The service provider is discovered automatically. Set the gateway and its keys in `.env` (all settings are in
`config/bayarkit.php`; publish it with `php artisan vendor:publish --tag=bayarkit-config`):

```dotenv
BAYARKIT_GATEWAY=toyyibpay
TOYYIBPAY_SECRET_KEY=...
TOYYIBPAY_CATEGORY_CODE=...
TOYYIBPAY_ENV=sandbox
```

For CHIP: `BAYARKIT_GATEWAY=chip`, `CHIP_SECRET_KEY`, `CHIP_BRAND_ID`, then run `php artisan bayarkit:chip-public-key`
once and paste the line it prints (`CHIP_PUBLIC_KEY="…"`) into `.env`. Without it every CHIP callback is refused.

```php
use Bayarkit\Confirm;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Customer;
use Bayarkit\Expected;
use Bayarkit\Gateway;
use Bayarkit\Laravel\Callback;
use Bayarkit\Laravel\Checkout;

// 1. Checkout: one reference per attempt; store the payment id with the order.
public function checkout(Order $order, Gateway $gateway)
{
    $payment = $gateway->createPayment(new CreatePaymentInput(
        reference: $order->reference,
        amount: $order->total_sen,            // integer sen: RM10.50 = 1050
        description: 'Order '.$order->reference,
        returnUrl: route('orders.thanks', $order),
        callbackUrl: route('payments.callback'),
        customer: new Customer($order->name, $order->email, $order->phone),
    ));
    $order->update(['payment_id' => $payment->id]);

    return Checkout::response($payment);       // 303 redirect, or a self-submitting form for POST gateways
}

// 2. Callback: never mark an order paid from the callback body or the return URL alone.
public function callback(Request $request, Gateway $gateway)
{
    $callback = Callback::from($request);      // keeps the raw body; reads multipart (toyyibPay posts multipart)
    $order = Order::where('payment_id', $gateway->verifyCallback($callback)?->id)->first();
    if ($order === null) {
        return response('unknown', 404);
    }
    if ($order->paid_at === null) {
        $result = Confirm::payment($gateway, $callback, new Expected($order->reference, $order->total_sen));
        if ($result->ok) {
            $order->update(['paid_at' => now()]); // idempotent: gateways retry callbacks
        }
        // else: log $result->reason and $result->detail
    }

    return response('OK');
}
```

Exclude the callback route from CSRF: the gateway cannot send your token, and the signature check plus
`Confirm::payment` (which asks the gateway again) are what protect it. Gateway calls go through Laravel's HTTP client,
so `Http::fake()` covers them in your tests. The same goes for anything that records outgoing requests (Laravel
Telescope's HTTP client watcher, logging middleware): toyyibPay takes its secret in the request BODY
(`userSecretKey`, `secretKey`), so mask those parameters there as you would a password. The checkout button text (shown when scripts are blocked) is translated
in BM and EN; publish `--tag=bayarkit-lang` to change it.

## Plain PHP

```php
$gateway = new Bayarkit\Gateways\ToyyibPay($secretKey, $categoryCode, 'sandbox');
$result = Bayarkit\Confirm::payment($gateway, Bayarkit\CallbackInput::fromGlobals(), new Bayarkit\Expected($ref, $sen));
```

## Testing your app without real money

Run a fake gateway with the npm package (`npx bayarkit-fake toyyibpay`) and point the gateway's `base_url` at the
`url` it prints. Its control API marks payments paid and serves the exact signed callback the real gateway would send:
`POST /paid/<id>`, `GET /callback/<id>`, `GET /callback/<id>/tampered`.

## Rules this package keeps

- Amounts are integer sen; ringgit strings are parsed as decimals, never through a float.
- `Confirm::payment` is the only way to "paid": signature → fresh gateway query → exact reference, amount, currency.
- Error messages quote the gateway's response only; keys never reach a message or a log.
- Every PHP adapter passes the same conformance cases as its TypeScript twin, against the same fake
  (`scripts/php-gate.sh`, part of `npm run gate`).
