<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Tests\Fixtures\Band;
use Superscript\Axiom\Tests\Fixtures\BandType;
use Superscript\Axiom\Types\IdentityLiteralType;
use Superscript\Axiom\Types\Shapes\DistinctShapes;
use Superscript\Axiom\Types\Shapes\IdentityLiteralShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\UnionType;

#[CoversClass(IdentityLiteralType::class)]
#[CoversClass(IdentityLiteralShape::class)]
#[UsesClass(TransformValueException::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(OpaqueShape::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(UnionShape::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(DistinctShapes::class)]
final class IdentityLiteralTypeTest extends TestCase
{
    #[Test]
    public function it_admits_only_values_sharing_its_identity_and_delivers_the_literal(): void
    {
        $literal = new Band(1, 5);
        $type = new IdentityLiteralType($literal, new BandType());

        $this->assertSame($literal, $type->assert(new Band(1.0, 5))->unwrap()->unwrap());
        $this->assertSame($literal, $type->coerce(new Band(1, 5.0))->unwrap()->unwrap());
        $this->assertStringStartsWith('Unable to transform into [1..5] from [Superscript\\Axiom\\Tests\\Fixtures\\Band Object', $type->assert(new Band(1, 6))->unwrapErr()->getMessage());
        $this->assertTrue($type->coerce(new Band(2, 5))->isErr());
        $this->assertTrue($type->coerce(null)->unwrap()->isNone());
        $this->assertSame('Expected a band.', $type->assert('1..5')->unwrapErr()->getMessage());
        $this->assertSame('Expected a band.', $type->coerce('1..5')->unwrapErr()->getMessage());
    }

    #[Test]
    public function its_value_must_belong_to_its_base(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An identity literal must be a value of its base band; got stdClass Object ().');

        new IdentityLiteralType(new \stdClass(), new BandType());
    }

    #[Test]
    public function it_formats_through_its_base(): void
    {
        $this->assertSame('1..5', (new IdentityLiteralType(new Band(1, 5), new BandType()))->format(new Band(1, 5)));
    }

    #[Test]
    public function its_shape_carries_the_value_the_base_and_the_identity_key(): void
    {
        $base = new BandType();
        $value = new Band(1, 5);
        $shape = (new IdentityLiteralType($value, $base))->shape();

        $this->assertInstanceOf(IdentityLiteralShape::class, $shape);
        $this->assertSame($value, $shape->value);
        $this->assertSame($base, $shape->type);
        $this->assertTrue($shape->base->equals(new OpaqueShape('band')));
        $this->assertSame('1.0..5.0', $shape->key);
    }

    #[Test]
    public function two_literals_with_equal_identity_keys_are_one_shape(): void
    {
        $int = new IdentityLiteralShape(new Band(1, 5), new BandType());
        $float = new IdentityLiteralShape(new Band(1.0, 5.0), new BandType());

        $this->assertTrue($int->equals($float));
        $this->assertTrue($float->equals($int));
        $this->assertSame('1..5', TypeDescriber::describe(new UnionType(
            new IdentityLiteralType(new Band(1, 5), new BandType()),
            new IdentityLiteralType(new Band(1.0, 5.0), new BandType()),
        )));
    }

    #[Test]
    public function literals_with_distinct_keys_or_bases_are_distinct_shapes(): void
    {
        $band = new IdentityLiteralShape(new Band(1, 5), new BandType());

        $this->assertFalse($band->equals(new IdentityLiteralShape(new Band(1, 6), new BandType())));
        $this->assertFalse($band->equals(new IdentityLiteralShape(new Band(1, 5), new BandType('age-band'))));
        $this->assertFalse($band->equals(new LiteralShape('1.0..5.0')));
        $this->assertFalse($band->equals(new OpaqueShape('band')));
    }
}
