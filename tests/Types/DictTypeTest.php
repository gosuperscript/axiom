<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Types\DictType;
use Superscript\Schema\Types\ListType;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Types\StringType;
use Superscript\Schema\Exceptions\TransformValueException;
use Superscript\Schema\Types\Type;

use function Superscript\Monads\Option\None;

#[CoversClass(DictType::class)]
#[CoversClass(TransformValueException::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(ListType::class)]
class DictTypeTest extends TestCase
{
    #[DataProvider('transformProvider')]
    #[Test]
    public function it_can_coerce_value(Type $type, mixed $value, array $expected): void
    {
        $type = new DictType($type);
        $result = $type->coerce($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function transformProvider(): array
    {
        return [
            [new NumberType(), ['a' => '1', 'b' => '2', 'c' => '3'], ['a' => 1, 'b' => 2, 'c' => 3]],
            [new NumberType(), '{"a": 1, "b": 2, "c": 3}', ['a' => 1, 'b' => 2, 'c' => 3]],
            [new ListType(new NumberType()), ['a' => ['1', '2', '3'], 'b' => ['4', '5', '6']], ['a' => [1, 2, 3], 'b' => [4, 5, 6]]],
        ];
    }

    #[Test]
    #[DataProvider('errorProvider')]
    public function it_returns_err_if_it_fails_to_coerce(DictType $type, mixed $value, \Throwable $err): void
    {
        $result = $type->coerce($value);
        $this->assertEquals($err, $result->unwrapErr());
        $this->assertEquals($err->getMessage(), $result->unwrapErr()->getMessage());
    }

    public static function errorProvider(): array
    {
        return [
            [new DictType(new NumberType()), ['a' => 'foo', 'b' => 'bar'], new TransformValueException(type: 'number', value: 'foo')],
        ];
    }

    public function it_can_assert_a_value(): void
    {
        $type = new DictType(new NumberType());
        $this->assertSame(['a' => 1, 'b' => 2], $type->assert(['a' => 1, 'b' => 2])->unwrapOr(None())->unwrapOr(null));
        $this->assertSame(['a' => 1.1, 'b' => 2.2], $type->assert(['a' => 1.1, 'b' => 2.2])->unwrapOr(None())->unwrapOr(null));
        $this->assertNull($type->assert(null)->unwrapOr(None())->unwrapOr(null));
    }

    public function it_returns_err_if_it_fails_to_assert(): void
    {
        $type = new DictType(new NumberType());
        $result = $type->assert($value = '123');
        $this->assertEquals(new TransformValueException(type: 'dict', value: $value), $result->unwrapErr());
        $this->assertEquals('Unable to assert [dict] from [\'123\']', $result->unwrapErr()->getMessage());
    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function it_can_compare_two_values(array $a, array $b, bool $expected): void
    {
        $type = new DictType(new NumberType());
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            [['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2], true],
            [['a' => 1, 'b' => 2], ['a' => '1', 'b' => '2'], false],
            [['a' => 1, 'b' => 2], ['a' => 1, 'c' => 2], false],
        ];
    }
}
