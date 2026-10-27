# bayarkit untuk PHP dan Laravel

[English](README.md)

Kembar PHP kepada pakej TypeScript: antara muka yang sama, peraturan "dibayar hanya bila gateway sendiri kata dibayar"
yang sama, dan diuji terhadap **gateway palsu yang sama** (`bayarkit-fake`). Gateway: **toyyibPay** (diukur pada
sandbox) dan **CHIP** (daripada OpenAPI rasmi, belum diukur). Gateway lain hanya dalam TypeScript buat masa ini.

Perlukan PHP 8.2+ dengan `curl`, `json` dan `openssl`. Tiada kebergantungan masa jalan; sokongan Laravel 11/12 pilihan.

```bash
composer require bayarkit/bayarkit
```

## Laravel

Penyedia servis dikesan secara automatik. Tetapkan gateway dan kuncinya dalam `.env` (semua tetapan dalam
`config/bayarkit.php`; terbitkan dengan `php artisan vendor:publish --tag=bayarkit-config`):

```dotenv
BAYARKIT_GATEWAY=toyyibpay
TOYYIBPAY_SECRET_KEY=...
TOYYIBPAY_CATEGORY_CODE=...
TOYYIBPAY_ENV=sandbox
```

Untuk CHIP: `BAYARKIT_GATEWAY=chip`, `CHIP_SECRET_KEY`, `CHIP_BRAND_ID`, `CHIP_ENV` (`test` atau `live`, sepadan
dengan kunci: wajib, ia menghalang bayaran ujian daripada menanda pesanan sebenar sebagai dibayar), kemudian jalankan
`php artisan bayarkit:chip-public-key` sekali dan tampal baris yang dicetak (`CHIP_PUBLIC_KEY="…"`) ke `.env`. Tanpanya
setiap callback CHIP ditolak.

```php
use Bayarkit\Confirm;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Customer;
use Bayarkit\Expected;
use Bayarkit\Gateway;
use Bayarkit\Laravel\Callback;
use Bayarkit\Laravel\Checkout;

// 1. Checkout: satu rujukan setiap percubaan; simpan id bayaran bersama pesanan.
public function checkout(Order $order, Gateway $gateway)
{
    $payment = $gateway->createPayment(new CreatePaymentInput(
        reference: $order->reference,
        amount: $order->total_sen,            // sen integer: RM10.50 = 1050
        description: 'Order '.$order->reference,
        returnUrl: route('orders.thanks', $order),
        callbackUrl: route('payments.callback'),
        customer: new Customer($order->name, $order->email, $order->phone),
    ));
    $order->update(['payment_id' => $payment->id]);

    return Checkout::response($payment);       // redirect 303, atau borang yang menghantar sendiri untuk gateway POST
}

// 2. Callback: jangan sesekali tanda pesanan dibayar daripada badan callback atau pautan kembali semata-mata.
public function callback(Request $request, Gateway $gateway)
{
    $callback = Callback::from($request);      // simpan badan mentah; baca multipart (toyyibPay menghantar multipart)
    $order = Order::where('payment_id', $gateway->verifyCallback($callback)?->id)->first();
    if ($order === null) {
        return response('unknown', 404);
    }
    if ($order->paid_at === null) {
        $result = Confirm::payment($gateway, $callback, new Expected($order->reference, $order->total_sen));
        if ($result->ok) {
            $order->update(['paid_at' => now()]); // idempoten: gateway menghantar semula callback
        }
        // jika tidak: log $result->reason dan $result->detail
    }

    return response('OK');
}
```

Kecualikan laluan callback daripada CSRF: gateway tidak boleh menghantar token anda, dan yang melindunginya ialah
semakan tandatangan serta `Confirm::payment` (yang bertanya gateway sekali lagi). Panggilan gateway melalui klien HTTP
Laravel, jadi `Http::fake()` meliputinya dalam ujian anda. Begitu juga apa-apa yang merekod permintaan keluar (pemerhati klien
HTTP Laravel Telescope, middleware log): toyyibPay menerima rahsianya dalam BADAN permintaan (`userSecretKey`,
`secretKey`), jadi sorokkan parameter itu di situ seperti kata laluan. Teks butang checkout (dipapar bila skrip disekat)
diterjemah dalam BM dan EN; terbitkan `--tag=bayarkit-lang` untuk mengubahnya.

## PHP biasa

```php
$gateway = new Bayarkit\Gateways\ToyyibPay($secretKey, $categoryCode, 'sandbox');
$result = Bayarkit\Confirm::payment($gateway, Bayarkit\CallbackInput::fromGlobals(), new Bayarkit\Expected($ref, $sen));
```

## Menguji aplikasi anda tanpa wang sebenar

Jalankan gateway palsu daripada pakej npm (Node.js 22.12+). Ia mencetak satu baris JSON dengan `url` untuk dijadikan URL
asas gateway, URL `control`, dan kunci yang diterimanya:

```bash
npx --package @bayarkit/testing bayarkit-fake toyyibpay
# {"gateway":"toyyibpay","url":"http://127.0.0.1:<port>/…","control":"http://127.0.0.1:<port>","options":{"secretKey":"fake-secret","categoryCode":"fakecat"}}
```

```dotenv
TOYYIBPAY_SECRET_KEY=fake-secret
TOYYIBPAY_CATEGORY_CODE=fakecat
TOYYIBPAY_ENV=sandbox
TOYYIBPAY_BASE_URL=<url>
```

Kemudian kawal melalui HTTP, pada URL `control` (bukan `url`): `POST <control>/paid/<id>` menanda bayaran dibayar,
`GET <control>/callback/<id>` memulangkan callback bertandatangan yang tepat seperti gateway sebenar (hantar ke laluan
callback anda), dan `GET <control>/callback/<id>/tampered` callback yang dipalsukan. Untuk CHIP:
`npx --package @bayarkit/testing bayarkit-fake chip`, `CHIP_SECRET_KEY=fake-secret`, `CHIP_BRAND_ID=fake-brand`,
`CHIP_ENV=test`, `CHIP_BASE_URL=<url>`, kemudian `php artisan bayarkit:chip-public-key` sekali.

## Peraturan yang dipegang pakej ini

- Amaun ialah sen integer; rentetan ringgit dihurai sebagai perpuluhan, tidak pernah melalui float.
- `Confirm::payment` satu-satunya jalan ke "dibayar": tandatangan → tanya gateway semula → rujukan, amaun dan mata wang
  tepat.
- Mesej ralat memetik jawapan gateway sahaja; kunci tidak pernah masuk ke mesej atau log.
- Setiap adapter PHP lulus kes keakuran yang sama dengan kembar TypeScriptnya, terhadap gateway palsu yang sama
  (`scripts/php-gate.sh`, sebahagian `npm run gate`).
