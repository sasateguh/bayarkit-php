<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Unit;

use Bayarkit\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The SAME vectors as the TypeScript core (packages/core/test/money-vectors.json): the twins cannot drift apart. */
final class MoneyTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: int|null}> */
    public static function toSenVectors(): array
    {
        $rows = [];
        foreach (self::vectors()['toSen'] as $i => [$input, $sen]) {
            $rows["#{$i} ".var_export($input, true)] = [$input, $sen];
        }

        return $rows;
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function toRinggitVectors(): array
    {
        $rows = [];
        foreach (self::vectors()['toRinggit'] as [$sen, $text]) {
            $rows["{$sen}"] = [$sen, $text];
        }

        return $rows;
    }

    #[DataProvider('toSenVectors')]
    public function test_to_sen_matches_the_typescript_core(mixed $input, ?int $sen): void
    {
        $this->assertSame($sen, Money::toSen($input));
    }

    #[DataProvider('toRinggitVectors')]
    public function test_to_ringgit_matches_the_typescript_core(int $sen, string $text): void
    {
        $this->assertSame($text, Money::toRinggit($sen));
    }

    public function test_refuses_values_json_cannot_carry(): void
    {
        foreach ([null, [], true, NAN, INF] as $bad) {
            $this->assertNull(Money::toSen($bad), var_export($bad, true));
        }
    }

    /** @return array{toSen: list<array{0: mixed, 1: int|null}>, toRinggit: list<array{0: int, 1: string}>} */
    private static function vectors(): array
    {
        /** @var array{toSen: list<array{0: mixed, 1: int|null}>, toRinggit: list<array{0: int, 1: string}>} */
        return json_decode((string) file_get_contents(__DIR__.'/../../../packages/core/test/money-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
