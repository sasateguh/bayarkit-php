<?php

declare(strict_types=1);

namespace Bayarkit;

/** What you charged for the order, from YOUR database — never from the callback. */
final readonly class Expected
{
    public function __construct(
        public string $reference,
        /** Amount in sen that you charged for this order. */
        public int $amount,
        /** Null = the adapter's `info()->currency` (MYR for every Malaysian gateway). */
        public ?string $currency = null,
    ) {}
}
