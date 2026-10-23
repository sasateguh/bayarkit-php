<?php

declare(strict_types=1);

namespace Bayarkit;

final readonly class CreatedPayment
{
    public function __construct(
        /** The id to pass to `getPayment`, and the id a callback will carry. Store it with your order. */
        public string $id,
        /** Where the payment page is. When `form` is set, POST the form instead (`Checkout` picks the right one). */
        public string $url,
        public mixed $raw = null,
        public ?PaymentForm $form = null,
    ) {}
}
