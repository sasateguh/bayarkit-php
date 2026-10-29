<?php

declare(strict_types=1);

namespace Bayarkit\Laravel;

use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\Gateways\Chip;
use Bayarkit\Gateways\Paddle;
use Bayarkit\Gateways\PayPal;
use Bayarkit\Gateways\ToyyibPay;
use Bayarkit\Http\Transport;

/** Builds gateways from config. `gateway()` = the default one; `gateway('chip')` = a named one (e.g. during a switch). */
final class GatewayManager
{
    /** @var array<string, Gateway> */
    private array $built = [];

    /** @param array<mixed> $config the `bayarkit` config array */
    public function __construct(private readonly array $config, private readonly Transport $transport) {}

    /** @throws GatewayError when the gateway is unknown or its keys are missing */
    public function gateway(?string $name = null): Gateway
    {
        $name ??= (string) ($this->config['default'] ?? '');

        return $this->built[$name] ??= $this->make($name);
    }

    private function make(string $name): Gateway
    {
        $c = $this->config['gateways'][$name] ?? null;
        if (! is_array($c)) {
            throw new GatewayError("no gateway \"{$name}\" in config/bayarkit.php", 'bayarkit', 0, 'CONFIG');
        }
        $s = static fn (string $k): string => is_scalar($c[$k] ?? null) ? (string) $c[$k] : '';
        $require = static function (string ...$keys) use ($s, $name): void {
            foreach ($keys as $k) {
                if ($s($k) === '') {
                    throw new GatewayError("{$name}: {$k} is not set (see config/bayarkit.php)", 'bayarkit', 0, 'CONFIG');
                }
            }
        };
        $timeout = (int) ($this->config['timeout_ms'] ?? 15_000);
        $baseUrl = $s('base_url') !== '' ? $s('base_url') : null;

        switch ($name) {
            case 'toyyibpay':
                $require('secret_key', 'category_code');

                return new ToyyibPay($s('secret_key'), $s('category_code'), $s('environment') ?: 'sandbox', $s('channel') ?: 'fpx',
                    (bool) ($c['charge_fee_to_customer'] ?? false), $baseUrl, $this->transport, $timeout);
            case 'chip':
                $require('secret_key', 'brand_id', 'environment');
                $methods = array_values(array_filter(array_map('trim', explode(',', $s('payment_methods')))));

                return new Chip($s('secret_key'), $s('brand_id'), $s('public_key') ?: null, $s('environment'),
                    $methods, $baseUrl, $this->transport, $timeout);
            case 'paddle':
                $require('api_key', 'webhook_secret');

                return new Paddle($s('api_key'), $s('webhook_secret'), $s('currency') ?: 'USD', $s('tax_category') ?: 'standard',
                    $s('checkout_url') ?: null, (int) ($s('tolerance_seconds') ?: 5), $baseUrl, $this->transport, $timeout);
            case 'paypal':
                $require('client_id', 'client_secret', 'webhook_id');

                return new PayPal($s('client_id'), $s('client_secret'), $s('webhook_id'), $s('environment') ?: 'sandbox',
                    $s('currency') ?: 'USD', $s('brand_name') ?: null, $s('shipping_preference') ?: 'NO_SHIPPING', $baseUrl,
                    $this->transport, $timeout);
            default:
                throw new GatewayError("gateway \"{$name}\" has no PHP adapter yet", 'bayarkit', 0, 'CONFIG');
        }
    }
}
