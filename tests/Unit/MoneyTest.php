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

    /** @return array<string, array{0: int|float, 1: string}> */
    public static function numberTextVectors(): array
    {
        $rows = [];
        /** @var list<array{0: int|float, 1: string}> $all */
        $all = json_decode((string) file_get_contents(__DIR__.'/../../../packages/core/test/number-text-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($all as $i => [$n, $text]) {
            $rows["#{$i} {$text}"] = [$n, $text];
        }

        return $rows;
    }

    /** Text::of writes numbers exactly like JavaScript's String() (the file is checked against JavaScript itself). */
    #[DataProvider('numberTextVectors')]
    public function test_text_of_writes_numbers_like_javascript(int|float $n, string $text): void
    {
        $this->assertSame($text, \Bayarkit\Text::of($n));
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
