<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Exceptions\TransformValueException;

use function Superscript\Monads\Option\None;

#[CoversClass(NumberType::class)]
#[CoversClass(TransformValueException::class)]
class NumberTypeTest extends TestCase
{
    #[DataProvider('transformProvider')]
    #[Test]
    public function it_can_transform_a_value(mixed $value, int|float|null $expected)
    {
        $type = new NumberType();
        $this->assertSame($expected, $type->transform($value)->unwrapOr(None())->unwrapOr(null));
    }

    public static function transformProvider(): array
    {
        return [
            [1, 1],
            [1.1, 1.1],
            ['42', 42],
            ['1.1', 1.1],
            ['45%', 0.45],
            ['', null],
            ['null', null],
        ];
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_transform()
    {
        $type = new NumberType();
        $result = $type->transform($value = 'foobar');
        $this->assertEquals(new TransformValueException(type: 'numeric', value: $value), $result->unwrapErr());
        $this->assertEquals('Unable to transform into [numeric] from [\'foobar\']', $result->unwrapErr()->getMessage());

    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function it_can_compare_two_values(int|float $a, int|float $b, bool $expected)
    {
        $type = new NumberType();
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            [1, 1, true],
            [1.1, 1.1, true],
            [1, 1.1, false],
            [1, 2, false],
        ];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function it_can_format_value(int|float $value, string $expected)
    {
        $type = new NumberType();
        $this->assertSame($expected, $type->format($value));
    }

    public static function formatProvider(): array
    {
        return [
            [1, '1'],
            [1.1, '1.1'],
            [10000, '10,000'],
        ];
    }
}
