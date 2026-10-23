<?php

declare(strict_types=1);

namespace Bayarkit;

/**
 * A value from a gateway's JSON as text, the way JavaScript's String() writes it, so the PHP and TypeScript twins read
 * the same thing: true → "true" (PHP's cast gives "1", which toyyibPay's status map reads as PAID), arrays and objects
 * → "" (PHP's cast warns, and Laravel turns the warning into an exception).
 */
final class Text
{
    public static function of(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => '',
        };
    }

    /**
     * ECMAScript Number::toString for a float: the shortest digits that read back as the same double, laid out the way
     * JavaScript does (plain from 1e-7 up to 1e21, exponent outside). PHP's own cast depends on the `precision` ini
     * setting and writes "1.0E+20".
     */
    private static function number(float $v): string
    {
        if (is_nan($v)) {
            return 'NaN';
        }
        if (is_infinite($v)) {
            return $v > 0 ? 'Infinity' : '-Infinity';
        }
        if ($v == 0.0) {
            return '0'; // JavaScript writes -0 as "0" too
        }
        $sign = $v < 0 ? '-' : '';
        $a = abs($v);
        $s = '';
        for ($p = 0; $p <= 16; $p++) {
            $s = sprintf('%.'.$p.'e', $a);
            if ((float) $s === $a) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $s);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $digits = $digits === '' ? '0' : $digits;
        $k = strlen($digits);
        $n = (int) $exponent + 1; // the value is 0.<digits> × 10^n
        if ($k <= $n && $n <= 21) {
            return $sign.$digits.str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return $sign.'0.'.str_repeat('0', -$n).$digits;
        }
        $e = $n - 1;

        return $sign.$digits[0].($k > 1 ? '.'.substr($digits, 1) : '').'e'.($e > 0 ? '+' : '-').abs($e);
    }
}
