<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Exceptions\AssertException;
use Superscript\Schema\Types\ListType;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Types\StringType;
use Superscript\Schema\Exceptions\TransformValueException;
use Superscript\Schema\Types\Type;

use function Superscript\Monads\Option\None;

#[CoversClass(ListType::class)]
#[CoversClass(TransformValueException::class)]
#[CoversClass(AssertException::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
class ListTypeTest extends TestCase
{
    #[DataProvider('transformProvider')]
    #[Test]
    public function it_can_coerce_value(Type $type, mixed $value, mixed $expected)
    {
        $type = new ListType($type);
        $result = $type->coerce($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function transformProvider(): array
    {
        return [
            [new NumberType(), ['1', '2', '3'], [1, 2, 3]],
            [new NumberType(), '[1, 2, 3]', [1, 2, 3]],
            [new NumberType(), '{"a": 1, "b": 2, "c": 3}', [1, 2, 3]],
            [new ListType(new NumberType()), [['1', '2', '3'], ['4', '5', '6']], [[1, 2, 3], [4, 5, 6]]],
            [new StringType(), null, null],
        ];
    }

    #[Test]
    #[DataProvider('errorProvider')]
    public function it_returns_err_if_it_fails_to_coerce(ListType $type, array $value, \Throwable $err): void
    {
        $result = $type->coerce($value);
        $this->assertEquals($result->unwrapErr(), $err);
        $this->assertEquals($result->unwrapErr()->getMessage(), $err->getMessage());
    }

    public static function errorProvider(): array
    {
        return [
            [new ListType(new NumberType()), ['a', 'b', 'c'], new TransformValueException(type: 'number', value: 'a')],
            [new ListType(new StringType()), ['a', 'b', ''], new \InvalidArgumentException('List item can not be [None]')],
        ];
    }

    #[Test]
    public function it_can_assert_a_value()
    {
        $type = new ListType(new NumberType());
        $result = $type->assert([1, 2, 3]);
        $this->assertTrue($result->isOk());
        $this->assertSame([1, 2, 3], $result->unwrapOr(None())->unwrapOr(null));
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_assert()
    {
        $type = new ListType(new NumberType());
        $result = $type->assert([1, '2', 3]);
        $this->assertFalse($result->isOk());
        $this->assertEquals($result->unwrapErr(), new AssertException(type: 'number', value: '2'));
    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function it_can_compare_two_values(array $a, array $b, bool $expected)
    {
        $type = new ListType(new NumberType());
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            [[1, 2], [1, 2], true],
            [[1, 2], ['1', '2'], false],
        ];
    }
}
