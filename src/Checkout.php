<?php

declare(strict_types=1);

namespace Bayarkit;

/**
 * Sending the customer to the payment page: a 303 redirect, or, for gateways that take a POSTed form, a page that
 * submits it. Framework-free; `Bayarkit\Laravel\Checkout::response()` wraps it in a Laravel response.
 */
final class Checkout
{
    /** The page's only script; its hash is pinned in the CSP. A test fails until both match. */
    public const SCRIPT = 'document.forms[0].submit()';

    public const SCRIPT_HASH = 'sha256-ePniVEkSivX/c7XWBGafqh8tSpiRrKiqYeqbG7N1TOE=';

    /** Headers for the auto-post page. No Referrer-Policy: iPay88 checks the Referer against the registered site. */
    public const HEADERS = [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Robots-Tag' => 'noindex',
        'Content-Security-Policy' => "default-src 'none'; script-src '".self::SCRIPT_HASH."'; base-uri 'none'; frame-ancestors 'none'",
    ];

    /**
     * A page that POSTs the form. The button stays visible so the customer can continue when scripts are blocked;
     * every name and value is HTML-escaped.
     */
    public static function autoPostHtml(PaymentForm $form, string $label = 'Continue to payment'): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $inputs = '';
        foreach ($form->fields as $k => $v) {
            $inputs .= '<input type="hidden" name="'.$e((string) $k).'" value="'.$e((string) $v).'">';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>'.$e($label).'</title></head><body>'
            .'<form method="post" action="'.$e(self::httpUrl($form->action, 'form action')).'" accept-charset="UTF-8">'
            .$inputs.'<button type="submit">'.$e($label).'</button></form><script>'.self::SCRIPT.'</script></body></html>';
    }

    /** @throws GatewayError when the URL is not http(s) */
    public static function httpUrl(string $url, string $what = 'payment url'): string
    {
        // No whitespace or control characters anywhere: they would be a header injection in Location.
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new GatewayError("{$what} contains whitespace or control characters", 'bayarkit', 0, 'INVALID_URL');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new GatewayError("{$what} must be an http(s) URL", 'bayarkit', 0, 'INVALID_URL');
        }

        return $url;
    }
}
