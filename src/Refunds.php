<?php

declare(strict_types=1);

namespace Bayarkit;

/**
 * Implemented by adapters that can refund (optional, like `refundPayment?` in @bayarkit/core). Check with
 * `$gateway instanceof Refunds`.
 */
interface Refunds extends Gateway
{
    /**
     * Ask the gateway to return money for a paid payment, in full or in part. Call `$input->check()` with what the
     * gateway's own record says was paid before sending anything.
     *
     * @throws GatewayError
     */
    public function refundPayment(RefundInput $input): RefundRecord;
}
