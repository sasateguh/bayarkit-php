<?php

declare(strict_types=1);

namespace Bayarkit;

/** What an adapter declares about itself. */
final readonly class GatewayInfo
{
    public function __construct(
        public string $name,
        /** Smallest amount the gateway accepts, in sen. */
        public int $minimumAmount,
        /** `measured` = checked against the gateway's sandbox on `$evidenceDate`; `docs` = official documentation only. */
        public string $evidence,
        public string $evidenceDate,
        public string $evidenceSource,
        /** The currency this adapter charges in, and the one `Confirm` expects when the order names none. */
        public string $currency = 'MYR',
    ) {}
}
