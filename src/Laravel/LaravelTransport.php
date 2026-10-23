<?php

declare(strict_types=1);

namespace Bayarkit\Laravel;

use Bayarkit\Http\Response;
use Bayarkit\Http\Transport;
use Bayarkit\Http\TransportError;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Sends through Laravel's HTTP client, so `Http::fake()` and `Http::preventStrayRequests()` cover gateway calls. The
 * timeout is whole seconds, rounded up: Laravel 11 declares `timeout(int)`, and a float there is a TypeError.
 */
final class LaravelTransport implements Transport
{
    public function __construct(private readonly Factory $http) {}

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $request = $this->http->withHeaders($headers)->timeout(max(1, (int) ceil($timeoutMs / 1000)))->withOptions(['allow_redirects' => false]);
        if ($body !== null) {
            $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/octet-stream');
        }
        try {
            $res = $request->send($method, $url);
        } catch (ConnectionException|TransferException) {
            // Laravel 11 converts only Guzzle's ConnectException; TLS, send/receive and partial-transfer failures arrive
            // as Guzzle exceptions. Laravel 12 converts more. Either way: no status, so "unreachable".
            throw new TransportError('connection failed');
        }

        return new Response($res->status(), $res->body());
    }
}
