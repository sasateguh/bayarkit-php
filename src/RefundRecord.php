<?php

declare(strict_types=1);

namespace Bayarkit;

/** The gateway's answer to a refund request. */
final readonly class RefundRecord
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public RefundStatus $status,
        /** Amount in sen. */
        public int $amount,
        public string $currency,
        public mixed $raw = null,
    ) {}
}
