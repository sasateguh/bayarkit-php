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
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => '',
        };
    }
}
