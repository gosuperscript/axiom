<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OpaqueType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
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
#[UsesClass(TypeDescriber::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(TypeRelations::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DictShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
final class ValueEqualityTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function it_compares_by_value_never_by_juggling(mixed $left, mixed $right, bool $expected): void
    {
        $this->assertSame($expected, ValueEquality::equals($left, $right));
        $this->assertSame($expected, ValueEquality::equals($right, $left));
    }

    public static function cases(): \Generator
    {
        yield 'numbers compare numerically across int/float' => [1, 1.0, true];
        yield 'distinct numbers differ' => [1, 2, false];
        yield 'strings compare strictly' => ['a', 'a', true];
        yield 'numeric strings are not numbers' => [1, '1', false];
        yield 'numeric strings compare strictly to each other' => ['1e3', '1000', false];
        yield 'booleans are not numbers' => [true, 1, false];
        yield 'booleans compare strictly' => [true, true, true];
        yield 'null equals only null' => [null, null, true];
        yield 'null equals nothing else' => [null, 0, false];
        yield 'lists compare element-wise' => [['a', 1], ['a', 1.0], true];
        yield 'lists of different length differ' => [['a'], ['a', 'b'], false];
        yield 'nested lists recurse' => [['a', ['b']], ['a', ['c']], false];
        yield 'list keys matter' => [[0 => 'a'], [1 => 'a'], false];
        yield 'a list is not a scalar' => [['a'], 'a', false];
        yield 'element juggling is dead: true is not 1 inside lists' => [[true], [1], false];
    }

    #[Test]
    public function membership_is_value_equality(): void
    {
        $this->assertTrue(ValueEquality::contains([1, 2, 3], 2.0));
        $this->assertFalse(ValueEquality::contains([1, 2, 3], '2'));
        $this->assertFalse(ValueEquality::contains([1, 2], true));
        $this->assertFalse(ValueEquality::contains([], 'anything'));
        $this->assertTrue(ValueEquality::contains(['a', 'b'], 'a'));
    }

    #[Test]
    #[DataProvider('supportCases')]
    public function it_declares_the_types_over_which_value_equality_is_total(Type $left, Type $right, bool $supported): void
    {
        $this->assertSame($supported, ValueEquality::supports($left, $right)->isOk());
        $this->assertSame($supported, ValueEquality::supports($right, $left)->isOk());
    }

    public static function supportCases(): \Generator
    {
        yield 'different scalar bases are supported' => [new NumberType(), new StringType(), true];
        yield 'booleans and literals are supported leaves' => [new BooleanType(), new LiteralType(true), true];
        yield 'absence and Never are supported' => [new OptionType(new NumberType()), new NeverType(), true];
        yield 'supported unions are universal' => [new UnionType(new NumberType(), new StringType()), new BooleanType(), true];
        yield 'supported lists, dicts, and records recurse' => [
            new RecordType([
                'items' => new ListType(new NumberType()),
                'labels' => new DictType(new StringType()),
            ]),
            new RecordType([]),
            true,
        ];
        yield 'Unknown is unsupported even though its runtime value might be scalar' => [new UnknownType(), new NumberType(), false];
        yield 'opaque equality belongs to its owning package' => [new OpaqueType('Money'), new OpaqueType('Money'), false];
        yield 'an option containing an opaque is unsupported' => [new OptionType(new OpaqueType('Money')), new NumberType(), false];
        yield 'one unsupported union member refuses the union' => [
            new UnionType(new NumberType(), new OpaqueType('Money')),
            new NumberType(),
            false,
        ];
        yield 'opaque list elements are unsupported when reachable' => [new ListType(new OpaqueType('Money')), new ListType(new OpaqueType('Money')), false];
        yield 'opaque dict values are unsupported' => [new DictType(new OpaqueType('Money')), new DictType(new OpaqueType('Money')), false];
        yield 'opaque record fields are unsupported' => [
            new RecordType(['price' => new OpaqueType('Money')]),
            new RecordType(['price' => new OpaqueType('Money')]),
            false,
        ];
        yield 'an empty bounded list never observes its element type' => [
            new ListType(new OpaqueType('Money'), max: 0),
            new ListType(new UnknownType(), max: 0),
            true,
        ];
    }

    #[Test]
    #[DataProvider('supportedValuePairs')]
    public function every_supported_type_pair_has_total_runtime_equality(
        Type $leftType,
        mixed $left,
        Type $rightType,
        mixed $right,
        bool $expected,
    ): void {
        $this->assertTrue($leftType->assert($left)->isOk(), 'the specimen must inhabit its declared left type');
        $this->assertTrue($rightType->assert($right)->isOk(), 'the specimen must inhabit its declared right type');
        $this->assertTrue(ValueEquality::supports($leftType, $rightType)->isOk());
        $this->assertSame($expected, ValueEquality::equals($left, $right));
    }

    public static function supportedValuePairs(): \Generator
    {
        yield 'different scalar bases are total and false' => [
            new NumberType(),
            1,
            new StringType(),
            '1',
            false,
        ];
        yield 'optional absence is a supported value' => [
            new OptionType(new NumberType()),
            null,
            new OptionType(new StringType()),
            null,
            true,
        ];
        yield 'supported union members are total' => [
            new UnionType(new NumberType(), new StringType()),
            'one',
            new BooleanType(),
            true,
            false,
        ];
        yield 'nested structural values recurse' => [
            new RecordType(['items' => new ListType(new NumberType())]),
            ['items' => [1, 2]],
            new RecordType(['items' => new ListType(new NumberType())]),
            ['items' => [1.0, 2.0]],
            true,
        ];
        yield 'an empty list never observes its opaque element type' => [
            new ListType(new OpaqueType('Money'), max: 0),
            [],
            new ListType(new UnknownType(), max: 0),
            [],
            true,
        ];
    }

    #[Test]
    public function support_and_overlap_answer_independent_questions(): void
    {
        $number = new NumberType();
        $string = new StringType();
        $unknown = new UnknownType();
        $money = new OpaqueType('Money');

        $this->assertTrue(ValueEquality::supports($number, $string)->isOk(), 'supported but disjoint');
        $this->assertTrue(TypeRelations::overlaps($number, $string)->isErr());

        $this->assertTrue(ValueEquality::supports($unknown, $number)->isErr(), 'overlapping but unsupported');
        $this->assertTrue(TypeRelations::overlaps($unknown, $number)->isOk());

        $this->assertTrue(ValueEquality::supports($money, $money)->isErr(), 'nominally identical but package-owned');
        $this->assertTrue(TypeRelations::overlaps($money, $money)->isOk());
    }

    #[Test]
    public function an_unsupported_pair_preserves_both_operand_diagnostics(): void
    {
        $mismatch = ValueEquality::supports(new UnknownType(), new OpaqueType('Money'))->unwrapErr();

        $this->assertSame(
            "Value equality is not defined for Unknown and Money.\n"
            . "  The left operand Unknown contains Unknown or opaque values; claim or coerce Unknown first, and let the package that owns an opaque type define its equality.\n"
            . '  The right operand Money contains Unknown or opaque values; claim or coerce Unknown first, and let the package that owns an opaque type define its equality.',
            $mismatch->describe(),
        );
    }

    #[Test]
    #[DataProvider('unsupportedRuntimeValues')]
    public function it_never_silently_falls_back_to_object_identity(mixed $left, mixed $right): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value equality is defined only for null, scalar, and array values; got stdClass.');

        ValueEquality::equals($left, $right);
    }

    public static function unsupportedRuntimeValues(): \Generator
    {
        yield 'unsupported left value' => [new \stdClass(), 1];
        yield 'unsupported right value' => [1, new \stdClass()];
        yield 'unsupported nested value never falls back to identity' => [
            ['value' => new \stdClass()],
            ['value' => new \stdClass()],
        ];
    }
}
