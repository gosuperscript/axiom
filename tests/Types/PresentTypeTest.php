<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

#[CoversClass(PresentType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OptionShape::class)]
final class PresentTypeTest extends TestCase
{
    #[Test]
    public function an_option_projects_to_its_inner_type(): void
    {
        $inner = new NumberType();

        $this->assertSame($inner, PresentType::of(new OptionType($inner)));
    }

    #[Test]
    public function a_present_type_answers_itself(): void
    {
        $type = new NumberType();

        $this->assertSame($type, PresentType::of($type));
    }

    /**
     * Nesting collapses in the shape algebra but not in the types
     * themselves: member access on an optional owner wraps an
     * already-optional field. The projection must still be present, or an
     * operator would be told it may get nothing when the value is there.
     */
    #[Test]
    public function every_option_constructor_is_peeled(): void
    {
        $inner = new NumberType();

        $this->assertSame($inner, PresentType::of(new OptionType(new OptionType($inner))));
        $this->assertSame($inner, PresentType::of(new OptionType(new OptionType(new OptionType($inner)))));
    }

    #[Test]
    public function option_shaped_optionality_projects_through_the_shape(): void
    {
        // A host type that is not OptionType but canonically option-shaped
        // still projects to its present member, reified from the shape.
        $type = new class implements Type {
            public function assert(mixed $value): Result
            {
                return Ok(\Superscript\Monads\Option\Some($value));
            }

            public function coerce(mixed $value): Result
            {
                return $this->assert($value);
            }

            public function format(mixed $value): string
            {
                return '';
            }

            public function shape(): Shape
            {
                return new OptionShape(new NumberShape());
            }
        };

        $this->assertInstanceOf(NumberType::class, PresentType::of($type));
    }
}
