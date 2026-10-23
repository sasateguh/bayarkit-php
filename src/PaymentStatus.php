<?php

declare(strict_types=1);

namespace Bayarkit;

/** Normalised payment states. `Unknown` means the gateway answered with something this adapter has not seen. */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
