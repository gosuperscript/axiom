<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\StringType;

#[CoversClass(OptionType::class)]
#[UsesClass(TransformValueException::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OptionShape::class)]
final class OptionTypeTest extends TestCase
{
    #[Test]
    public function null_is_a_present_value_of_the_option(): void
    {
        $type = new OptionType(new NumberType());

        $asserted = $type->assert(null)->unwrap();
        $this->assertTrue($asserted->isSome());
        $this->assertNull($asserted->unwrap());

        $coerced = $type->coerce(null)->unwrap();
        $this->assertTrue($coerced->isSome());
        $this->assertNull($coerced->unwrap());
    }

    #[Test]
    public function present_values_delegate_to_the_inner_type(): void
    {
        $type = new OptionType(new NumberType());

        $this->assertSame(5, $type->assert(5)->unwrap()->unwrap());
        $this->assertSame(5, $type->coerce('5')->unwrap()->unwrap());
        $this->assertTrue($type->assert('not a number')->isErr());
        $this->assertTrue($type->coerce('not a number')->isErr());
    }

    #[Test]
    public function an_inner_absence_reading_coerces_to_present_null(): void
    {
        $type = new OptionType(new StringType());

        $coerced = $type->coerce('')->unwrap();

        $this->assertTrue($coerced->isSome());
        $this->assertNull($coerced->unwrap());
    }

    #[Test]
    public function it_compares_null_aware(): void
    {
        $type = new OptionType(new NumberType());

        $this->assertTrue($type->compare(null, null));
        $this->assertFalse($type->compare(null, 5));
        $this->assertFalse($type->compare(5, null));
        $this->assertTrue($type->compare(5, 5));
        $this->assertFalse($type->compare(5, 6));
    }

    #[Test]
    public function absence_comparison_never_reaches_the_inner_type(): void
    {
        $inner = new class implements \Superscript\Axiom\Types\Type {
            public function assert(mixed $value): \Superscript\Monads\Result\Result
            {
                return \Superscript\Monads\Result\Ok(\Superscript\Monads\Option\Some($value));
            }

            public function coerce(mixed $value): \Superscript\Monads\Result\Result
            {
                return $this->assert($value);
            }

            public function compare(mixed $a, mixed $b): bool
            {
                return ($a === null) !== ($b === null);
            }

            public function format(mixed $value): string
            {
                return '';
            }

            public function shape(): \Superscript\Axiom\Types\Shapes\Shape
            {
                return new \Superscript\Axiom\Types\Shapes\NumberShape();
            }
        };

        $type = new OptionType($inner);

        $this->assertTrue($type->compare(null, null));
        $this->assertFalse($type->compare(null, 5));
        $this->assertFalse($type->compare(5, null));
        $this->assertFalse($type->compare(5, 5));
    }

    #[Test]
    public function it_formats_absence_as_empty(): void
    {
        $type = new OptionType(new NumberType());

        $this->assertSame('', $type->format(null));
        $this->assertSame('5', $type->format(5));
    }

    #[Test]
    public function it_projects_to_an_option_shape(): void
    {
        $shape = (new OptionType(new NumberType()))->shape();

        $this->assertInstanceOf(OptionShape::class, $shape);
        $this->assertInstanceOf(NumberShape::class, $shape->inner);
    }
}
