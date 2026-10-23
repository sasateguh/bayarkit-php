<?php

declare(strict_types=1);

namespace Bayarkit;

use RuntimeException;

/**
 * Thrown when the gateway refuses or cannot be reached. The message never contains secrets: adapters only quote the
 * gateway's response, and secrets travel only in requests.
 */
final class GatewayError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $gateway,
        /** HTTP status, or 0 when the gateway could not be reached or the input was refused before calling it. */
        public readonly int $status = 0,
        /** The gateway's own error code or text when it gave one. */
        public readonly ?string $gatewayCode = null,
    ) {
        parent::__construct($gateway.': '.$message);
    }
}
