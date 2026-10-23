<?php

declare(strict_types=1);

namespace Bayarkit;

/** A browser form that starts a payment, for gateways that take a POST instead of a redirect. */
final readonly class PaymentForm
{
    /** @param array<string, string> $fields */
    public function __construct(
        public string $action,
        public array $fields,
        public string $method = 'POST',
    ) {}
}
