<?php

declare(strict_types=1);

namespace Bayarkit;

/** Integer-sen helpers. Ringgit strings are parsed as decimals, never through a float. */
final class Money
{
    /** 1050 → "10.50". */
    public static function toRinggit(int $sen): string
    {
        $sign = $sen < 0 ? '-' : '';
        $abs = abs($sen);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "10.50" / "10.5" / 10.5 → 1050. Null when the value is not a plain number ("1,050.00" included). */
    public static function toSen(mixed $ringgit): ?int
    {
        if (is_int($ringgit)) {
            return $ringgit * 100;
        }
        if (is_float($ringgit)) {
            return is_finite($ringgit) ? (int) round($ringgit * 100) : null;
        }
        if (! is_string($ringgit) || ! preg_match('/^\s*(-?)(\d+)(?:\.(\d*))?\s*$/', $ringgit, $m)) {
            return null;
        }
        $decimals = str_pad($m[3] ?? '', 3, '0');
        $sen = (int) $m[2] * 100 + (int) substr($decimals, 0, 2) + ((int) $decimals[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$sen : $sen;
    }

    /** @throws GatewayError (code INVALID_AMOUNT) before any gateway call */
    public static function assertSen(int $sen, int $minimum, string $gateway): void
    {
        if ($sen < $minimum) {
            throw new GatewayError("amount must be integer sen ≥ {$minimum}, got {$sen}", $gateway, 0, 'INVALID_AMOUNT');
        }
    }
}
