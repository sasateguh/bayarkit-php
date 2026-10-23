<?php

declare(strict_types=1);

namespace Bayarkit;

final readonly class ConfirmResult
{
    public const INVALID_SIGNATURE = 'invalid_signature';

    public const NOT_PAID = 'not_paid';

    public const MISMATCH = 'mismatch';

    public const GATEWAY_ERROR = 'gateway_error';

    private function __construct(
        public bool $ok,
        /** null when ok; otherwise one of the constants above. */
        public ?string $reason,
        public string $detail,
        public ?PaymentRecord $payment,
    ) {}

    public static function paid(PaymentRecord $payment): self
    {
        return new self(true, null, '', $payment);
    }

    public static function refused(string $reason, string $detail, ?PaymentRecord $payment = null): self
    {
        return new self(false, $reason, $detail, $payment);
    }
}
