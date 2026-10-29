<?php

declare(strict_types=1);

namespace Bayarkit;

final readonly class RefundInput
{
    public function __construct(
        /** The id `createPayment` returned (the same id `getPayment` takes). */
        public string $paymentId,
        /** Amount in sen, at most what was paid. Partial refunds are allowed where the gateway allows them. */
        public int $amount,
        /** Shown to the gateway (and sometimes to the customer); adapters trim it to the gateway's limits. */
        public ?string $reason = null,
        /** Lets a retried call return the same refund instead of a second one (where the gateway supports it). */
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * The checks every adapter makes before it asks the gateway for a refund (`checkRefundAmount` in the TypeScript
     * twin, same messages): a payment id, more than 0 sen, and no more than `$paid`.
     *
     * @throws GatewayError
     */
    public function check(string $gateway, int $paid): void
    {
        if ($this->paymentId === '') {
            throw new GatewayError('refund needs the payment id', $gateway);
        }
        if ($this->amount <= 0) {
            throw new GatewayError("refund amount must be a whole number of sen above 0, got {$this->amount}", $gateway);
        }
        if ($this->amount > $paid) {
            throw new GatewayError("refund of {$this->amount} sen exceeds the {$paid} sen paid", $gateway);
        }
    }
}
