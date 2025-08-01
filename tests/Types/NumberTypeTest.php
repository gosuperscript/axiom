<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Exceptions\AssertException;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Exceptions\TransformValueException;

use function Superscript\Monads\Option\None;

#[CoversClass(NumberType::class)]
#[CoversClass(TransformValueException::class)]
#[CoversClass(AssertException::class)]
class NumberTypeTest extends TestCase
{
    #[DataProvider('transformProvider')]
    #[Test]
    public function it_can_coerce_a_value(mixed $value, int|float|null $expected): void
    {
        $type = new NumberType();
        $this->assertSame($expected, $type->coerce($value)->unwrapOr(None())->unwrapOr(null));
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
    public function it_returns_err_if_it_fails_to_coerce(): void
    {
        $type = new NumberType();
        $result = $type->coerce($value = 'foobar');
        $this->assertEquals(new TransformValueException(type: 'number', value: $value), $result->unwrapErr());
        $this->assertEquals('Unable to transform into [number] from [\'foobar\']', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function it_can_assert_value(): void
    {
        $type = new NumberType();
        $this->assertSame(1, $type->assert(1)->unwrapOr(None())->unwrapOr(null));
        $this->assertSame(1.1, $type->assert(1.1)->unwrapOr(None())->unwrapOr(null));
        $this->assertNull($type->assert(null)->unwrapOr(None())->unwrapOr(null));
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_assert(): void
    {
        $type = new NumberType();
        $result = $type->assert($value = '123');
        $this->assertEquals(new AssertException(type: 'number', value: $value), $result->unwrapErr());
        $this->assertEquals('Expected [number], got [\'123\']', $result->unwrapErr()->getMessage());
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
}
