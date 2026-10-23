<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Support;

use Bayarkit\CallbackInput;
use RuntimeException;

/**
 * The SAME fake gateways the TypeScript suite uses, run by `bayarkit-fake` (scripts/php-gate.sh starts them and passes
 * `BAYARKIT_FAKES` = {"toyyibpay": {url, control, options}, …}). Missing = a hard failure, never a skip.
 */
final class Fakes
{
    /** @return array{url: string, control: string, options: array<string, string>} */
    public static function get(string $gateway): array
    {
        $all = json_decode((string) getenv('BAYARKIT_FAKES'), true);
        $fake = is_array($all) ? ($all[$gateway] ?? null) : null;
        if (! is_array($fake) || ! is_string($fake['url'] ?? null) || ! is_string($fake['control'] ?? null)) {
            throw new RuntimeException("BAYARKIT_FAKES has no {$gateway}: run the PHP tests with scripts/php-gate.sh");
        }

        /** @var array{url: string, control: string, options: array<string, string>} $fake */
        return $fake;
    }

    public static function markPaid(string $gateway, string $id): void
    {
        self::control($gateway, 'POST', '/paid/'.rawurlencode($id));
    }

    public static function callbackFor(string $gateway, string $id, bool $tampered = false): CallbackInput
    {
        $body = self::control($gateway, 'GET', '/callback/'.rawurlencode($id).($tampered ? '/tampered' : ''));
        $data = json_decode($body, true);
        if (! is_array($data)) {
            throw new RuntimeException("control server answered: {$body}");
        }

        return CallbackInput::fromArray($data);
    }

    private static function control(string $gateway, string $method, string $path): string
    {
        $ch = curl_init(self::get($gateway)['control'].$path);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (! is_string($body) || $status >= 300) {
            throw new RuntimeException("control {$method} {$path} → {$status}");
        }

        return $body;
    }
}
