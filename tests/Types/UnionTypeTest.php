<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\UnionType;

#[CoversClass(UnionType::class)]
#[UsesClass(TransformValueException::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(BooleanShape::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(StringShape::class)]
#[UsesClass(UnionShape::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
final class UnionTypeTest extends TestCase
{
    #[Test]
    public function it_admits_a_value_of_any_member(): void
    {
        $enum = new UnionType(new LiteralType('shop'), new LiteralType('office'));

        $this->assertSame('shop', $enum->assert('shop')->unwrap()->unwrap());
        $this->assertSame('office', $enum->assert('office')->unwrap()->unwrap());
        $this->assertTrue($enum->assert('warehouse')->isErr());
    }

    #[Test]
    public function it_coerces_through_the_first_accepting_member(): void
    {
        $union = new UnionType(new NumberType(), new StringType());

        $this->assertSame(5, $union->coerce('5')->unwrap()->unwrap());
        $this->assertSame('abc', $union->coerce('abc')->unwrap()->unwrap());
    }

    #[Test]
    public function a_failed_union_names_the_union_in_its_error(): void
    {
        $enum = new UnionType(new LiteralType('shop'), new LiteralType('office'));
        $error = $enum->assert('warehouse')->unwrapErr();

        $this->assertStringContainsString("'shop' | 'office'", $error->getMessage());
    }

    #[Test]
    public function it_requires_at_least_one_member(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UnionType();
    }


    #[Test]
    public function it_formats_through_the_inhabited_member(): void
    {
        $union = new UnionType(new NumberType(), new StringType());

        $this->assertSame('5', $union->format(5));
        $this->assertSame('abc', $union->format('abc'));
        $this->assertSame('true', $union->format(true));
    }

    #[Test]
    public function it_projects_to_a_canonical_union_shape(): void
    {
        $enum = new UnionType(new LiteralType('shop'), new LiteralType('office'));

        $this->assertInstanceOf(UnionShape::class, $enum->shape());

        $single = new UnionType(new NumberType());

        $this->assertInstanceOf(NumberShape::class, $single->shape());
    }
}
