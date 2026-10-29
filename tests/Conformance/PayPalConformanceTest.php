<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Conformance;

use Bayarkit\Gateway;
use Bayarkit\Gateways\PayPal;
use Bayarkit\Tests\Support\Fakes;

final class PayPalConformanceTest extends GatewayConformance
{
    protected function fake(): string
    {
        return 'paypal';
    }

    protected function gateway(): Gateway
    {
        $f = Fakes::get('paypal');

        return new PayPal($f['options']['clientId'], $f['options']['clientSecret'], $f['options']['webhookId'], baseUrl: $f['url']);
    }
}
