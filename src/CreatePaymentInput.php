<?php

declare(strict_types=1);

namespace Bayarkit;

use DateTimeInterface;

final readonly class CreatePaymentInput
{
    public function __construct(
        /** Your own order or invoice reference. Echoed back by the gateway and checked by `Confirm::payment`. */
        public string $reference,
        /** Amount in sen (RM 10.50 = 1050). */
        public int $amount,
        /** Short text shown on the gateway's payment page. Adapters trim it to the gateway's limits. */
        public string $description,
        /** Where the customer's browser lands after paying. Never treat this visit as proof of payment. */
        public string $returnUrl,
        /** Server-to-server notification URL. */
        public string $callbackUrl,
        public Customer $customer = new Customer,
        public string $currency = 'MYR',
        public ?DateTimeInterface $expiresAt = null,
    ) {}
}
