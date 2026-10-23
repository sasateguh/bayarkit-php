<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Laravel;

use Bayarkit\CreatedPayment;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Customer;
use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\Gateways\Chip;
use Bayarkit\Gateways\ToyyibPay;
use Bayarkit\Laravel\BayarkitServiceProvider;
use Bayarkit\Laravel\Callback;
use Bayarkit\Laravel\Checkout;
use Bayarkit\Laravel\GatewayManager;
use Bayarkit\PaymentForm;
use Bayarkit\PaymentStatus;
use Bayarkit\Tests\Support\Fakes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

final class LaravelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BayarkitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bayarkit.gateways.toyyibpay.secret_key', 'cfg-secret');
        $app['config']->set('bayarkit.gateways.toyyibpay.category_code', 'cfgcat');
    }

    public function test_binds_the_gateway_named_in_config(): void
    {
        $this->assertInstanceOf(ToyyibPay::class, $this->app->make(Gateway::class));
        config(['bayarkit.default' => 'chip', 'bayarkit.gateways.chip.secret_key' => 's', 'bayarkit.gateways.chip.brand_id' => 'b', 'bayarkit.gateways.chip.environment' => 'test']);
        $this->app->forgetInstance(GatewayManager::class);
        $this->assertInstanceOf(Chip::class, $this->app->make(Gateway::class));
    }

    public function test_a_missing_key_names_the_setting_not_a_value(): void
    {
        config(['bayarkit.gateways.toyyibpay.secret_key' => '']);
        $this->app->forgetInstance(GatewayManager::class);
        try {
            $this->app->make(Gateway::class);
            $this->fail('expected a GatewayError');
        } catch (GatewayError $e) {
            $this->assertStringContainsString('secret_key is not set', $e->getMessage());
            $this->assertStringNotContainsString('cfgcat', $e->getMessage());
        }
    }

    public function test_an_unknown_gateway_is_refused(): void
    {
        $this->expectException(GatewayError::class);
        $this->app->make(GatewayManager::class)->gateway('paypal');
    }

    public function test_calls_go_through_the_laravel_http_client_so_http_fake_works(): void
    {
        Http::preventStrayRequests();
        // The measured sandbox shape: leading tabs, text/html.
        Http::fake(['dev.toyyibpay.com/*' => Http::response("\t\t\t\t[{\"BillCode\":\"abc123\"}]", 200, ['Content-Type' => 'text/html'])]);
        $created = $this->app->make(Gateway::class)->createPayment(new CreatePaymentInput('ORD1', 1050, 'Order 1', 'https://shop.example/r', 'https://shop.example/cb', new Customer('Ali', 'ali@example.com')));
        $this->assertSame('abc123', $created->id);
        $this->assertSame('https://dev.toyyibpay.com/abc123', $created->url);
        Http::assertSent(fn ($r) => $r->url() === 'https://dev.toyyibpay.com/index.php/api/createBill'
            && $r['billAmount'] === '1050' && $r['userSecretKey'] === 'cfg-secret');
    }

    public function test_a_timeout_that_is_not_whole_seconds_still_works_on_laravel_11(): void
    {
        // Laravel 11 declares timeout(int): 2500 ms once became a float and a TypeError on every call.
        config(['bayarkit.timeout_ms' => 2500]);
        $this->app->forgetInstance(GatewayManager::class);
        Http::fake(['dev.toyyibpay.com/*' => Http::response("\t\t\t\tNo data found!", 200)]);
        $this->assertSame(PaymentStatus::Pending, $this->app->make(Gateway::class)->getPayment('abc123')->status);
    }

    public function test_a_tls_or_transfer_failure_is_unreachable_not_an_escaping_guzzle_exception(): void
    {
        Http::fake(fn () => throw new \GuzzleHttp\Exception\RequestException('SSL certificate problem', new \GuzzleHttp\Psr7\Request('POST', 'https://dev.toyyibpay.com/x')));
        try {
            $this->app->make(Gateway::class)->getPayment('abc123');
            $this->fail('expected a GatewayError');
        } catch (GatewayError $e) {
            $this->assertSame(0, $e->status);
            $this->assertStringContainsString('unreachable', $e->getMessage());
        }
    }

    public function test_reads_a_multipart_callback_the_way_toyyibpay_sends_it(): void
    {
        $request = Request::create('/cb', 'POST', ['billcode' => 'abc', 'status' => '1'], server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
        $cb = Callback::from($request);
        $this->assertSame(['billcode' => 'abc', 'status' => '1'], $cb->fields);
    }

    public function test_reads_a_json_callback_keeping_the_exact_raw_body(): void
    {
        $raw = '{"id":"p1","status":"paid","is_test":true,"amount":1050,"nested":{"x":1}}';
        $request = Request::create('/cb', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'c2ln'], content: $raw);
        $cb = Callback::from($request);
        $this->assertSame($raw, $cb->rawBody);
        $this->assertSame('c2ln', $cb->headers['x-signature']);
        $this->assertSame(['id' => 'p1', 'status' => 'paid', 'is_test' => 'true', 'amount' => '1050'], $cb->fields);
    }

    public function test_reads_the_query_string_of_a_return_url(): void
    {
        $cb = Callback::from(Request::create('/return?billcode=abc&status_id=1', 'GET'));
        $this->assertSame(['billcode' => 'abc', 'status_id' => '1'], $cb->fields);
    }

    public function test_confirms_a_real_fake_callback_end_to_end_through_a_laravel_request(): void
    {
        $f = Fakes::get('toyyibpay');
        $g = new ToyyibPay($f['options']['secretKey'], $f['options']['categoryCode'], baseUrl: $f['url']);
        $created = $g->createPayment(new CreatePaymentInput('LARA1', 1500, 'x', 'https://shop.example/r', 'https://shop.example/cb', new Customer('A', 'a@example.com')));
        Fakes::markPaid('toyyibpay', $created->id);
        // Deliver the fake's callback as a form POST, read it back the way a controller would.
        $request = Request::create('/cb', 'POST', Fakes::callbackFor('toyyibpay', $created->id)->fields, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        $result = \Bayarkit\Confirm::payment($g, Callback::from($request), new \Bayarkit\Expected('LARA1', 1500));
        $this->assertTrue($result->ok, $result->detail);
    }

    public function test_checkout_redirects_with_303_or_posts_the_form_in_the_app_language(): void
    {
        $redirect = Checkout::response(new CreatedPayment('b1', 'https://dev.toyyibpay.com/b1'));
        $this->assertSame(303, $redirect->getStatusCode());
        $this->assertSame('https://dev.toyyibpay.com/b1', $redirect->headers->get('Location'));

        $this->app->setLocale('ms');
        $page = Checkout::response(new CreatedPayment('p1', 'https://pay.example/entry', form: new PaymentForm('https://pay.example/entry', ['a' => 'b'])));
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control')); // Symfony adds ", private"
        $this->assertStringContainsString("default-src 'none'", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertSame('noindex', $page->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('Teruskan ke pembayaran', (string) $page->getContent());
        $this->app->setLocale('en');
        $page = Checkout::response(new CreatedPayment('p1', 'https://pay.example/entry', form: new PaymentForm('https://pay.example/entry', ['a' => 'b'])));
        $this->assertStringContainsString('Continue to payment', (string) $page->getContent());
    }

    public function test_the_chip_public_key_command_prints_one_env_line(): void
    {
        $f = Fakes::get('chip');
        config(['bayarkit.gateways.chip' => ['secret_key' => $f['options']['secretKey'], 'brand_id' => $f['options']['brandId'], 'environment' => 'test', 'base_url' => $f['url']]]);
        $this->app->forgetInstance(GatewayManager::class);
        $this->assertSame(0, Artisan::call('bayarkit:chip-public-key'));
        $out = trim(Artisan::output());
        $this->assertMatchesRegularExpression('/^CHIP_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\\\\n.+-----END PUBLIC KEY-----"$/', $out);
        $this->assertStringNotContainsString("\n", $out);
    }
}
