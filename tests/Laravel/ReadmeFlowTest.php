<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Laravel;

use Bayarkit\Confirm;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Customer;
use Bayarkit\Expected;
use Bayarkit\Gateway;
use Bayarkit\Laravel\BayarkitServiceProvider;
use Bayarkit\Laravel\Callback;
use Bayarkit\Laravel\Checkout;
use Bayarkit\Tests\Support\Fakes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/** The README's checkout + callback controllers, run as real Laravel routes against the toyyibPay fake. */
final class ReadmeFlowTest extends TestCase
{
    /** @var array<string, array{reference: string, total_sen: int, payment_id: ?string, paid: bool}> */
    private static array $orders = [];

    protected function getPackageProviders($app): array
    {
        return [BayarkitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $f = Fakes::get('toyyibpay');
        $app['config']->set('bayarkit.gateways.toyyibpay', [
            'secret_key' => $f['options']['secretKey'], 'category_code' => $f['options']['categoryCode'], 'base_url' => $f['url'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        Route::post('/checkout/{ref}', function (string $ref, Gateway $gateway) {
            $order = &self::$orders[$ref];
            $payment = $gateway->createPayment(new CreatePaymentInput(
                reference: $order['reference'], amount: $order['total_sen'], description: 'Order '.$ref,
                returnUrl: 'https://shop.example/thanks', callbackUrl: 'https://shop.example/payments/callback',
                customer: new Customer('Ali', 'ali@example.com', '0123456789'),
            ));
            $order['payment_id'] = $payment->id;

            return Checkout::response($payment);
        });
        Route::post('/payments/callback', function (Request $request, Gateway $gateway) {
            $callback = Callback::from($request);
            $id = $gateway->verifyCallback($callback)?->id;
            $ref = null;
            foreach (self::$orders as $r => $o) {
                if ($id !== null && $o['payment_id'] === $id) {
                    $ref = $r;
                }
            }
            if ($ref === null) {
                return response('unknown', 404);
            }
            if (! self::$orders[$ref]['paid']) {
                $result = Confirm::payment($gateway, $callback, new Expected(self::$orders[$ref]['reference'], self::$orders[$ref]['total_sen']));
                self::$orders[$ref]['paid'] = $result->ok;
            }

            return response('OK');
        });
    }

    public function test_the_readme_flow_marks_an_order_paid_only_after_the_gateway_confirms(): void
    {
        self::$orders['R1'] = ['reference' => 'R1', 'total_sen' => 2500, 'payment_id' => null, 'paid' => false];
        $this->post('/checkout/R1')->assertStatus(303);
        $id = (string) self::$orders['R1']['payment_id'];
        $this->assertNotSame('', $id);

        // Before payment: a correctly signed callback is not enough.
        $this->post('/payments/callback', Fakes::callbackFor('toyyibpay', $id)->fields)->assertOk();
        $this->assertFalse(self::$orders['R1']['paid']);

        // A tampered callback after payment is refused (unknown order: the signature did not verify).
        Fakes::markPaid('toyyibpay', $id);
        $this->post('/payments/callback', Fakes::callbackFor('toyyibpay', $id, true)->fields)->assertNotFound();
        $this->assertFalse(self::$orders['R1']['paid']);

        // The real callback, delivered twice (gateways retry): paid once, same answer.
        $this->post('/payments/callback', Fakes::callbackFor('toyyibpay', $id)->fields)->assertOk();
        $this->post('/payments/callback', Fakes::callbackFor('toyyibpay', $id)->fields)->assertOk();
        $this->assertTrue(self::$orders['R1']['paid']);
    }
}
