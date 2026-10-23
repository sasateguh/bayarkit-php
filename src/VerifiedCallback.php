<?php

declare(strict_types=1);

namespace Bayarkit;

/** A callback whose signature checked out. It is still only a claim until `getPayment` agrees. */
final readonly class VerifiedCallback
{
    /** @param array<string, string> $raw */
    public function __construct(
        public string $id,
        public string $reference,
        public PaymentStatus $claimedStatus,
        public array $raw,
    ) {}
}
