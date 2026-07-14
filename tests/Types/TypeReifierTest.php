<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OpaqueType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\TypeReifier;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(TypeReifier::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(DictType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OpaqueType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(BooleanShape::class)]
#[UsesClass(DictShape::class)]
#[UsesClass(ListShape::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(NeverShape::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OpaqueShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(RecordShape::class)]
#[UsesClass(StringShape::class)]
#[UsesClass(UnionShape::class)]
#[UsesClass(UnknownShape::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
final class TypeReifierTest extends TestCase
{
    /**
     * The round-trip law: reify(shape)->shape() must equal the input —
     * the canonical type is a faithful stand-in for the projection.
     */
    #[Test]
    #[DataProvider('shapes')]
    public function reification_round_trips_through_the_shape(Shape $shape): void
    {
        $this->assertTrue(TypeReifier::reify($shape)->shape()->equals($shape));
    }

    public static function shapes(): \Generator
    {
        yield 'Boolean' => [new BooleanShape()];
        yield 'Number' => [new NumberShape()];
        yield 'String' => [new StringShape()];
        yield 'literal' => [new LiteralShape('shop')];
        yield 'option' => [new OptionShape(new NumberShape())];
        yield 'union' => [UnionShape::of(new LiteralShape('a'), new LiteralShape('b'))];
        yield 'list' => [new ListShape(new NumberShape())];
        yield 'bounded list' => [new ListShape(new NumberShape(), 1, 3)];
        yield 'dict' => [new DictShape(new StringShape())];
        yield 'record' => [new RecordShape(['a' => new NumberShape(), 'b' => new OptionShape(new StringShape())])];
        yield 'open record' => [new RecordShape(['a' => new NumberShape()], open: true)];
        yield 'unknown' => [new UnknownShape()];
        yield 'never' => [new NeverShape()];
        yield 'opaque' => [new OpaqueShape('ClaimId')];
        yield 'parameterized opaque' => [new OpaqueShape('money', ['currency' => new LiteralShape('GBP')])];
    }

    #[Test]
    public function it_rejects_shapes_outside_the_sealed_vocabulary(): void
    {
        $rogue = new class extends Shape {
            public function equals(Shape $other): bool
            {
                return false;
            }
        };

        $this->expectException(InvalidArgumentException::class);

        TypeReifier::reify($rogue);
    }
}
