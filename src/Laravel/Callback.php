<?php

declare(strict_types=1);

namespace Bayarkit\Laravel;

use Bayarkit\CallbackInput;
use Illuminate\Http\Request;

/**
 * A gateway callback from a Laravel request. Keeps the raw body (header-signed gateways like CHIP verify the exact
 * bytes) and the fields Laravel parsed — toyyibPay's real callbacks are multipart (measured), and multipart bodies
 * are only in `$request->request`, never in `getContent()`.
 *
 * Exclude the callback route from CSRF (`VerifyCsrfToken::$except` / `validateCsrfTokens(except: …)`): the gateway
 * cannot send your token; the signature check and `Confirm::payment` are what protect it.
 */
final class Callback
{
    public static function from(Request $request): CallbackInput
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $value = $values[0] ?? null;
            if (is_string($value)) {
                $headers[strtolower((string) $name)] = $value;
            }
        }
        $raw = (string) $request->getContent();

        return new CallbackInput(
            CallbackInput::fieldsOf($headers['content-type'] ?? '', $raw, $request->request->all(), $request->query->all()),
            $raw,
            $headers,
        );
    }
}
