<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Conformance;

use Bayarkit\CreatePaymentInput;
use Bayarkit\Customer;
use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\Gateways\Chip;
use Bayarkit\Tests\Support\Fakes;

final class ChipConformanceTest extends GatewayConformance
{
    private static ?string $pem = null;

    protected function fake(): string
    {
        return 'chip';
    }

    protected function gateway(): Gateway
    {
        $f = Fakes::get('chip');
        // The key is fetched the way an app would: once, with the same secret key, through the adapter itself.
        self::$pem ??= (new Chip($f['options']['secretKey'], $f['options']['brandId'], baseUrl: $f['url']))->fetchPublicKey();

        return new Chip($f['options']['secretKey'], $f['options']['brandId'], self::$pem, baseUrl: $f['url']);
    }

    public function test_accepts_a_public_key_stored_with_literal_backslash_n(): void
    {
        $f = Fakes::get('chip');
        $this->gateway();
        $g = new Chip($f['options']['secretKey'], $f['options']['brandId'], str_replace("\n", '\n', (string) self::$pem), baseUrl: $f['url']);
        $created = $g->createPayment($this->input());
        $this->assertNotNull($g->verifyCallback(Fakes::callbackFor('chip', $created->id)));
    }

    public function test_without_a_public_key_every_callback_is_refused(): void
    {
        $f = Fakes::get('chip');
        $g = new Chip($f['options']['secretKey'], $f['options']['brandId'], baseUrl: $f['url']);
        $created = $g->createPayment($this->input());
        $this->assertNull($g->verifyCallback(Fakes::callbackFor('chip', $created->id)));
    }

    public function test_refuses_a_callback_url_with_an_explicit_port_before_calling_chip(): void
    {
        foreach (['https://shop.example:443/cb', 'https://shop.example:8443/cb', 'http://shop.example:80/cb', 'https://user:pw@shop.example:443/cb', 'http://[::1]:8080/cb'] as $url) {
            try {
                $this->gateway()->createPayment(new CreatePaymentInput('R1', 1050, 'x', 'https://shop.example/r', $url, new Customer('A', 'a@example.com')));
                $this->fail("accepted {$url}");
            } catch (GatewayError $e) {
                $this->assertSame('webhooks_callback_port', $e->gatewayCode, $url);
            }
        }
        $this->assertFalse(Chip::hasExplicitPort('https://shop.example/cb?next=http://x:1'));
        $this->assertFalse(Chip::hasExplicitPort('https://user:pw@shop.example/cb'));
        $this->assertFalse(Chip::hasExplicitPort('http://[::1]/cb'));
    }

    public function test_reads_the_public_key_only_in_the_shape_chip_sends(): void
    {
        $this->gateway();
        $pem = (string) self::$pem;
        $this->assertSame($pem, Chip::decodePublicKey((string) json_encode($pem)));
        $this->assertNull(Chip::decodePublicKey($pem)); // bare PEM is not what CHIP sends
        $this->assertNull(Chip::decodePublicKey((string) json_encode(['public_key' => $pem]))); // the PHP SDK's wrong shape
    }

    public function test_a_test_purchase_never_confirms_against_a_live_config(): void
    {
        $f = Fakes::get('chip');
        $this->gateway();
        $live = new Chip($f['options']['secretKey'], $f['options']['brandId'], self::$pem, 'live', baseUrl: $f['url']);
        try {
            $live->createPayment($this->input());
            $this->fail('a test purchase was accepted by a live config');
        } catch (GatewayError $e) {
            $this->assertSame('MODE_MISMATCH', $e->gatewayCode);
        }
    }
}
