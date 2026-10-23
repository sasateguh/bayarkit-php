<?php

declare(strict_types=1);

namespace Bayarkit\Http;

final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
    ) {}
}
