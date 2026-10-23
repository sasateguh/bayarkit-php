<?php

declare(strict_types=1);

namespace Bayarkit\Laravel;

use Bayarkit\Checkout as Core;
use Bayarkit\CreatedPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * `return Checkout::response($payment);` from your checkout controller: a 303 redirect to the payment page, or, for
 * gateways that take a POSTed form, a page that submits it. The button text comes from the `bayarkit::checkout.continue`
 * translation (BM + EN shipped; publish the lang files to change it).
 */
final class Checkout
{
    public static function response(CreatedPayment $payment, ?string $label = null): RedirectResponse|Response
    {
        if ($payment->form === null) {
            return new RedirectResponse(Core::httpUrl($payment->url), 303);
        }
        $translated = __('bayarkit::checkout.continue');
        $label ??= is_string($translated) ? $translated : 'Continue to payment';

        return new Response(Core::autoPostHtml($payment->form, $label), 200, Core::HEADERS);
    }
}
