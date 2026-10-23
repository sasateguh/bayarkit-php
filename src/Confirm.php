<?php

declare(strict_types=1);

namespace Bayarkit;

/**
 * The only safe way to mark an order paid from a callback (the same rules as `confirmPayment` in @bayarkit/core):
 *
 * 1. the callback signature must verify (cheap filter, rejects forgeries);
 * 2. the gateway is asked again with `getPayment` (a valid signature can still be replayed or describe another order);
 * 3. the gateway's own record must say paid, for this reference, this exact amount and currency.
 *
 * Make "mark order paid" idempotent: gateways retry callbacks.
 */
final class Confirm
{
    public static function payment(Gateway $gateway, CallbackInput $callback, Expected $expected): ConfirmResult
    {
        $cb = $gateway->verifyCallback($callback);
        if ($cb === null) {
            return ConfirmResult::refused(ConfirmResult::INVALID_SIGNATURE, $gateway->info()->name.': callback signature did not verify');
        }
        if ($cb->reference !== '' && $cb->reference !== $expected->reference) {
            return ConfirmResult::refused(ConfirmResult::MISMATCH, "callback is for reference {$cb->reference}, expected {$expected->reference}");
        }
        try {
            $payment = $gateway->getPayment($cb->id);
        } catch (GatewayError $e) {
            return ConfirmResult::refused(ConfirmResult::GATEWAY_ERROR, $e->getMessage());
        }
        if ($payment->status !== PaymentStatus::Paid) {
            return ConfirmResult::refused(ConfirmResult::NOT_PAID, 'gateway reports '.$payment->status->value, $payment);
        }
        $problems = array_filter([
            $payment->reference !== $expected->reference ? "reference {$payment->reference} ≠ {$expected->reference}" : null,
            $payment->amount !== $expected->amount ? 'amount '.($payment->amount ?? 'unreadable')." sen ≠ {$expected->amount} sen" : null,
            $payment->currency !== $expected->currency ? "currency {$payment->currency} ≠ {$expected->currency}" : null,
        ]);
        if ($problems !== []) {
            return ConfirmResult::refused(ConfirmResult::MISMATCH, implode('; ', $problems), $payment);
        }

        return ConfirmResult::paid($payment);
    }
}
