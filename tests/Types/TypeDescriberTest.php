<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\RecordPropertyShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\TypeDescriber;

#[CoversClass(TypeDescriber::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
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
final class TypeDescriberTest extends TestCase
{
    #[Test]
    public function it_renders_a_type_class_without_its_namespace(): void
    {
        $this->assertSame('NumberType', TypeDescriber::describeClass(NumberType::class));
    }

    #[Test]
    #[DataProvider('shapes')]
    public function it_renders_shapes(Shape $shape, string $expected): void
    {
        $this->assertSame($expected, TypeDescriber::describeShape($shape));
    }

    public static function shapes(): \Generator
    {
        yield [new BooleanShape(), 'Boolean'];
        yield [new NumberShape(), 'Number'];
        yield [new StringShape(), 'String'];
        yield [new UnknownShape(), 'Unknown'];
        yield [new NeverShape(), 'Never'];
        yield [new OpaqueShape('ClaimId'), 'ClaimId'];
        yield [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            "money<currency: 'GBP'>",
        ];
        yield [
            new OpaqueShape('money', ['currency' => UnionShape::of(new LiteralShape('GBP'), new LiteralShape('USD')), 'precision' => new NumberShape()]),
            "money<currency: 'GBP' | 'USD', precision: Number>",
        ];

        yield [new LiteralShape('shop'), "'shop'"];
        yield [new LiteralShape("it's"), "'it's'"];
        yield [new LiteralShape(true), 'true'];
        yield [new LiteralShape(false), 'false'];
        yield [new LiteralShape(5), '5'];
        yield [new LiteralShape(5.5), '5.5'];

        yield [new OptionShape(new NumberShape()), 'Number?'];
        yield [
            new OptionShape(UnionShape::of(new LiteralShape('shop'), new LiteralShape('office'))),
            "('shop' | 'office')?",
        ];

        yield [UnionShape::of(new LiteralShape('shop'), new LiteralShape('office')), "'shop' | 'office'"];

        yield [new ListShape(new NumberShape()), 'List<Number>'];
        yield [new ListShape(new NumberShape(), 2, 2), 'List<Number, 2>'];
        yield [new ListShape(new NumberShape(), 1), 'List<Number, 1..>'];
        yield [new ListShape(new NumberShape(), 1, 3), 'List<Number, 1..3>'];

        yield [new DictShape(new NumberShape()), 'Dict<Number>'];

        yield [
            new RecordShape(['a' => new NumberShape(), 'b' => new OptionShape(new StringShape())]),
            '{a: Number, b: String?}',
        ];
        yield [
            new RecordShape(['answer' => new RecordPropertyShape(new OptionShape(new NumberShape()), true)]),
            '{answer: Optional<Number?>}',
        ];
        yield [new RecordShape([]), '{}'];
    }

    #[Test]
    public function it_renders_a_type_through_its_projection(): void
    {
        $this->assertSame('Number?', TypeDescriber::describe(new OptionType(new NumberType())));
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
        $this->expectExceptionMessage('the shape vocabulary is sealed');

        TypeDescriber::describeShape($rogue);
    }
}
