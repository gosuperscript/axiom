<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\IntegerType;
use Superscript\Axiom\Types\Shapes\NumberShape;

#[CoversClass(IntegerType::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(TransformValueException::class)]
final class IntegerTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('integerSpellings')]
    public function it_coerces_an_integer_spelling_to_its_int(mixed $value, int $expected): void
    {
        $this->assertSame($expected, (new IntegerType())->coerce($value)->unwrap()->unwrap());
    }

    public static function integerSpellings(): \Generator
    {
        yield 'int' => [3, 3];
        yield 'zero' => [0, 0];
        yield 'negative int' => [-4, -4];
        yield 'largest int' => [PHP_INT_MAX, PHP_INT_MAX];
        yield 'integer string' => ['3', 3];
        yield 'negative integer string' => ['-3', -3];
        yield 'signed integer string' => ['+3', 3];
        yield 'largest int string' => ['9223372036854775807', PHP_INT_MAX];
    }

    #[Test]
    #[DataProvider('blankValues')]
    public function it_coerces_a_blank_answer_to_absence(string $value): void
    {
        $this->assertTrue((new IntegerType())->coerce($value)->unwrap()->isNone());
    }

    public static function blankValues(): \Generator
    {
        yield 'empty string' => [''];
        yield 'null string' => ['null'];
    }

    #[Test]
    #[DataProvider('refusedValues')]
    public function it_refuses_to_coerce_anything_but_an_integer_spelling(mixed $value): void
    {
        $result = (new IntegerType())->coerce($value);

        $this->assertEquals(new TransformValueException(type: 'integer', value: $value), $result->unwrapErr());
    }

    public static function refusedValues(): \Generator
    {
        yield 'fractional float' => [2.5];
        yield 'whole float' => [3.0];
        yield 'fractional string' => ['0.5'];
        yield 'whole decimal string' => ['3.0'];
        yield 'exponent string' => ['1e3'];
        yield 'percentage' => ['45%'];
        yield 'string past the int range' => ['9223372036854775808'];
        yield 'non-numeric string' => ['three'];
        yield 'boolean' => [true];
        yield 'null' => [null];
        yield 'infinity' => [INF];
        yield 'array' => [[3]];
    }

    #[Test]
    public function it_asserts_only_ints(): void
    {
        $type = new IntegerType();

        $this->assertSame(3, $type->assert(3)->unwrap()->unwrap());
        $this->assertEquals(new TransformValueException(type: 'integer', value: 3.0), $type->assert(3.0)->unwrapErr());
        $this->assertEquals(new TransformValueException(type: 'integer', value: '3'), $type->assert('3')->unwrapErr());
        $this->assertSame('Unable to transform into [integer] from [3.0]', $type->assert(3.0)->unwrapErr()->getMessage());
    }

    #[Test]
    public function every_coerced_value_passes_assert(): void
    {
        $type = new IntegerType();

        foreach (self::integerSpellings() as [$value]) {
            $coerced = $type->coerce($value)->unwrap()->unwrap();

            $this->assertTrue($type->assert($coerced)->isOk());
        }
    }

    #[Test]
    public function it_formats_with_grouping(): void
    {
        $type = new IntegerType();

        $this->assertSame('3', $type->format(3));
        $this->assertSame('10,000', $type->format(10000));
        $this->assertSame('-1,250', $type->format(-1250));
    }

    #[Test]
    public function it_projects_the_integer_refinement_of_number(): void
    {
        $this->assertTrue((new IntegerType())->shape()->equals(new NumberShape(integer: true)));
        $this->assertFalse((new IntegerType())->shape()->equals(new NumberShape()));
    }
}
