<?php

declare(strict_types=1);

namespace Bayarkit;

final readonly class Customer
{
    public function __construct(
        public string $name = '',
        public string $email = '',
        public string $phone = '',
    ) {}
}
