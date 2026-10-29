# bayarkit untuk PHP dan Laravel

[English](README.md)

Kembar PHP kepada pakej TypeScript: antara muka yang sama, peraturan "dibayar hanya bila gateway sendiri kata dibayar"
yang sama, dan diuji terhadap **gateway palsu yang sama** (`bayarkit-fake`). Gateway: **toyyibPay** (diukur pada
sandbox), **CHIP** (daripada OpenAPI rasmi), serta **Paddle** dan **PayPal** untuk menjual ke luar negara (daripada
dokumen rasmi masing-masing); tiga yang akhir belum diukur. Gateway lain hanya dalam TypeScript buat masa ini.

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

Untuk Paddle dan PayPal, lihat [Paddle dan PayPal](#paddle-dan-paypal) di bawah: kedua-duanya mengecaj dalam USD secara
lalai, dan PayPal memerlukan langkah tangkap.

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

## Paddle dan PayPal

Kedua-duanya mengecaj dalam mata wang lain (USD secara lalai). `CreatePaymentInput` lalai kepada `MYR`, jadi hantar
`currency:` pada setiap bayaran, dan mata wang yang sama kepada `Expected`; amaun ialah integer dalam unit terkecil mata
wang itu (USD 10.50 = 1050). Kedua-duanya tidak menerima `callbackUrl` bagi setiap bayaran: masing-masing menghantar
notifikasi ke satu webhook yang ditetapkan dalam papan pemukanya, yang anda halakan ke laluan callback anda.

**Paddle** (penjual rasmi, tiada MYR): `BAYARKIT_GATEWAY=paddle`, `PADDLE_API_KEY` (`pdl_sdbx_…` atau `pdl_live_…`:
kunci menentukan sandbox atau live), `PADDLE_WEBHOOK_SECRET` (kunci tandatangan destinasi notifikasi), `PADDLE_CURRENCY`
(lalai `USD`). Tetapkan pautan pembayaran lalai dalam Paddle > Checkout dahulu (halaman anda sendiri yang memuatkan
Paddle.js), atau Paddle menolak setiap transaksi baharu. Tambah destinasi notifikasi untuk acara `transaction.*`. Harga
dihantar termasuk cukai, jadi pembeli membayar tepat amaun yang disemak `Confirm::payment`. Bayaran balik bermula
`pending` sehingga Paddle meluluskannya.

**PayPal** (Orders v2): `BAYARKIT_GATEWAY=paypal`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID` (id
webhook yang anda tambah pada aplikasi REST), `PAYPAL_ENVIRONMENT` (`sandbox` secara lalai, atau `live`),
`PAYPAL_CURRENCY` (lalai `USD`). MYR hanya berfungsi untuk akaun PayPal Malaysia. Bayaran ada dua langkah: pembeli
meluluskan di PayPal (belum dibayar), kemudian laluan kembali anda menangkap.

```php
use Bayarkit\Gateways\PayPal;

// Checkout: seperti di atas, dengan mata wang dinyatakan.
$payment = $gateway->createPayment(new CreatePaymentInput(
    reference: $order->reference,
    amount: $order->total_cents,              // USD 10.50 = 1050
    description: 'Order '.$order->reference,
    returnUrl: route('orders.thanks', $order),
    callbackUrl: route('payments.callback'),  // tidak dihantar: PayPal guna webhook pada aplikasi REST
    currency: 'USD',
));

// Laluan kembali: pembeli sudah meluluskan, belum ada wang berpindah. Tangkap dengan id yang disimpan (selamat diulang).
if ($gateway instanceof PayPal) {
    $gateway->capturePayment($order->payment_id);
}

// Laluan callback (webhook PayPal): tanda dibayar pada PAYMENT.CAPTURE.COMPLETED, seperti di atas.
$result = Confirm::payment($gateway, Callback::from($request), new Expected($order->reference, $order->total_cents, 'USD'));
```

`getPayment` hanya membaca; pesanan yang diluluskan tetapi belum ditangkap ialah `pending`. Mengesahkan webhook PayPal
memanggil PayPal (`verify-webhook-signature`); jika PayPal tidak dapat dicapai, `Confirm::payment` menjawab
`gateway_error` (cuba semula kemudian), bukan `invalid_signature`.

Kedua-dua adapter melaksanakan `Bayarkit\Refunds`: `$gateway->refundPayment(new RefundInput($paymentId, $amount))`
membuat bayaran balik penuh atau sebahagian (amaun melebihi baki ditolak sebelum memanggil gateway) dan memulangkan
`RefundRecord` dengan status `pending`, `succeeded`, `failed` atau `unknown`.

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
`CHIP_ENV=test`, `CHIP_BASE_URL=<url>`, kemudian `php artisan bayarkit:chip-public-key` sekali. Untuk Paddle dan
PayPal, jalankan `bayarkit-fake paddle` atau `bayarkit-fake paypal`, salin kunci daripada `options` ke tetapan `.env`
yang sepadan dan tetapkan `PADDLE_BASE_URL` atau `PAYPAL_BASE_URL` kepada `<url>`. Pada PayPal palsu,
`POST <control>/approve/<id>` ialah pembeli meluluskan tanpa tangkapan, supaya langkah tangkap anda boleh diuji;
`POST <control>/paid/<id>` meluluskan dan menangkap.

## Peraturan yang dipegang pakej ini

- Amaun ialah sen integer (unit terkecil bagi mata wang lain); rentetan perpuluhan dihurai dengan tepat, tidak pernah
  melalui float.
- `Confirm::payment` satu-satunya jalan ke "dibayar": tandatangan → tanya gateway semula → rujukan, amaun dan mata wang
  tepat.
- Mesej ralat memetik jawapan gateway sahaja; kunci tidak pernah masuk ke mesej atau log.
- Setiap adapter PHP lulus kes keakuran yang sama dengan kembar TypeScriptnya, terhadap gateway palsu yang sama
  (`scripts/php-gate.sh`, sebahagian `npm run gate`).
