<?php

declare(strict_types=1);

namespace Bayarkit;

/**
 * `Pending` = asked for but not final (some gateways approve refunds first); `Succeeded` = the gateway reports the money
 * returned; `Unknown` = a state this adapter has not seen.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
