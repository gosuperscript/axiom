<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

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
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeOrder;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(TypeOrder::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(DictType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(BooleanShape::class)]
#[UsesClass(DictShape::class)]
#[UsesClass(ListShape::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(NeverShape::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(StringShape::class)]
#[UsesClass(UnionShape::class)]
#[UsesClass(UnknownShape::class)]
final class TypeOrderTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function number_is_the_only_ordered_core_type(Type $type, bool $expected): void
    {
        $this->assertSame($expected, TypeOrder::hasDefinedOrder($type));
    }

    public static function cases(): \Generator
    {
        yield 'Number is ordered' => [new NumberType(), true];
        yield 'a number literal is ordered' => [new LiteralType(5), true];
        yield 'a union of number literals is ordered' => [new UnionType(new LiteralType(1), new LiteralType(2)), true];

        yield 'String is not ordered' => [new StringType(), false];
        yield 'Boolean is not ordered' => [new BooleanType(), false];
        yield 'a string literal is not ordered' => [new LiteralType('a'), false];
        yield 'a mixed-base union is not ordered' => [new UnionType(new LiteralType(1), new LiteralType('a')), false];
        yield 'Option is not ordered: null does not rank' => [new OptionType(new NumberType()), false];
        yield 'Unknown is not ordered' => [new UnknownType(), false];
        yield 'Never is not ordered' => [new NeverType(), false];
        yield 'lists are not ordered' => [new ListType(new NumberType()), false];
        yield 'dicts are not ordered' => [new DictType(new NumberType()), false];
    }
}
