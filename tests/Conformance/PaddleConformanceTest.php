<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Conformance;

use Bayarkit\Gateway;
use Bayarkit\Gateways\Paddle;
use Bayarkit\Tests\Support\Fakes;

final class PaddleConformanceTest extends GatewayConformance
{
    protected function fake(): string
    {
        return 'paddle';
    }

    protected function gateway(): Gateway
    {
        $f = Fakes::get('paddle');

        return new Paddle($f['options']['apiKey'], $f['options']['webhookSecret'], baseUrl: $f['url']);
    }
}
