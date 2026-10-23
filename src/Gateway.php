<?php

declare(strict_types=1);

namespace Bayarkit;

/** The contract every gateway adapter implements. Amounts are always integer sen. */
interface Gateway
{
    public function info(): GatewayInfo;

    /** @throws GatewayError */
    public function createPayment(CreatePaymentInput $input): CreatedPayment;

    /**
     * Ask the gateway for the current state. This, not the callback body, is the source of truth.
     *
     * @throws GatewayError
     */
    public function getPayment(string $id): PaymentRecord;

    /** Check a callback's signature. Returns null when it is not authentic; never throws on bad input. */
    public function verifyCallback(CallbackInput $callback): ?VerifiedCallback;
}
