<?php

declare(strict_types=1);

namespace Bayarkit\Http;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        if ($method === '') {
            throw new TransportError('empty HTTP method');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportError('curl_init failed');
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k.': '.$v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10_000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        if ($text === false) {
            // The curl error names the failure class only (timeout, DNS…); the URL and body are not repeated.
            $kind = curl_errno($ch);
            curl_close($ch);
            throw new TransportError('curl error '.$kind);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, (string) $text);
    }
}
