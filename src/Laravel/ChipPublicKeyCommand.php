<?php

declare(strict_types=1);

namespace Bayarkit\Laravel;

use Bayarkit\GatewayError;
use Bayarkit\Gateways\Chip;
use Illuminate\Console\Command;

/** Prints CHIP's callback public key as one .env line. Fetched with the configured secret key (test or live). */
final class ChipPublicKeyCommand extends Command
{
    protected $signature = 'bayarkit:chip-public-key';

    protected $description = 'Fetch the CHIP public key that signs callbacks and print it as CHIP_PUBLIC_KEY=…';

    public function handle(GatewayManager $gateways): int
    {
        try {
            $chip = $gateways->gateway('chip');
            if (! $chip instanceof Chip) {
                return self::FAILURE;
            }
            $pem = $chip->fetchPublicKey();
        } catch (GatewayError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line('CHIP_PUBLIC_KEY="'.str_replace("\n", '\n', trim($pem)).'"');

        return self::SUCCESS;
    }
}
