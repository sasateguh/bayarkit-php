<?php

declare(strict_types=1);

namespace Bayarkit;

use DateTimeImmutable;

/** The gateway's own record of a payment. This, not the callback body, is the source of truth. */
final readonly class PaymentRecord
{
    public function __construct(
        public string $id,
        public string $reference,
        public PaymentStatus $status,
        /** Amount in sen; null when the gateway gave no readable amount (never treated as a match). */
        public ?int $amount,
        public string $currency,
        public ?DateTimeImmutable $paidAt = null,
        /** Gateway fee in sen when the gateway reports one. */
        public ?int $fee = null,
        public mixed $raw = null,
    ) {}
}
