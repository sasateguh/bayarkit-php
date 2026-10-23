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

    /** Largest sen value both twins represent exactly (JavaScript's Number.MAX_SAFE_INTEGER). */
    public const MAX_SEN = 9007199254740991;

    /**
     * "10.50" / "10.5" / 10.5 → 1050. Null when the value is not a plain number ("1,050.00" included) or does not fit
     * in MAX_SEN — never a float, never an overflow.
     */
    public static function toSen(mixed $ringgit): ?int
    {
        // A number is read as the decimal text JavaScript writes for it (Text::of), never through `* 100` + round():
        // round() changed between PHP 8.3 and 8.4 (1.005 → 101 vs 100), and the TypeScript twin must agree.
        if (is_int($ringgit) || is_float($ringgit)) {
            return is_float($ringgit) && ! is_finite($ringgit) ? null : self::toSen(Text::of($ringgit));
        }
        // ASCII whitespace only, written out: the same set as the TypeScript twin, whatever PCRE's \s means.
        if (! is_string($ringgit) || ! preg_match('/^[ \t\n\r\f\v]*(-?)(\d+)(?:\.(\d*))?[ \t\n\r\f\v]*$/', $ringgit, $m)) {
            return null;
        }
        $whole = ltrim($m[2], '0');
        if (strlen($whole) > 14) {
            return null; // ≥ 10^14 ringgit cannot be ≤ MAX_SEN sen; checked on the text, before any arithmetic
        }
        $decimals = str_pad($m[3] ?? '', 3, '0');
        $sen = (int) $m[2] * 100 + (int) substr($decimals, 0, 2) + ((int) $decimals[2] >= 5 ? 1 : 0);
        if ($sen > self::MAX_SEN) {
            return null;
        }

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
