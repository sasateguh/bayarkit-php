<?php

declare(strict_types=1);

namespace Bayarkit\Http;

/**
 * How adapters reach a gateway. `CurlTransport` is the default; the Laravel provider uses `LaravelTransport` so that
 * `Http::fake()` works in your application's tests.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws TransportError when the gateway could not be reached (no HTTP status)
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response;
}
