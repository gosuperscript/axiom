<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\LogicalOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\SetOperands;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(BinaryOverloader::class)]
#[CoversClass(ComparisonOverloader::class)]
#[CoversClass(LogicalOverloader::class)]
#[CoversClass(NullOverloader::class)]
#[CoversClass(HasOverloader::class)]
#[CoversClass(InOverloader::class)]
#[CoversClass(IntersectsOverloader::class)]
#[CoversClass(SetOperands::class)]
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
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeOrder::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DictShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
#[UsesClass(\Superscript\Axiom\Types\RecordType::class)]
final class TypeOfTest extends TestCase
{
    /**
     * @param class-string<Type> $expected
     */
    #[Test]
    #[DataProvider('certifiedCases')]
    public function it_certifies(OperatorOverloader $rule, string $operator, Type $left, Type $right, string $expected): void
    {
        $result = $rule->typeOf($operator, $left, $right);

        $this->assertTrue($result->isOk(), $result->isErr() ? $result->unwrapErr()->describe() : '');
        $this->assertInstanceOf($expected, $result->unwrap());
    }

    public static function certifiedCases(): \Generator
    {
        $binary = new BinaryOverloader();
        yield 'numbers add to Number' => [$binary, '+', new NumberType(), new NumberType(), NumberType::class];
        yield 'number literals divide to Number' => [$binary, '/', new LiteralType(5), new LiteralType(2), NumberType::class];
        yield 'unknown is admitted at arithmetic' => [$binary, '*', new UnknownType(), new NumberType(), NumberType::class];
        yield 'a numeric enum multiplies' => [
            $binary, '*', new UnionType(new LiteralType(1), new LiteralType(2)), new NumberType(), NumberType::class,
        ];

        $comparison = new ComparisonOverloader();
        yield 'equality of overlapping types' => [$comparison, '==', new NumberType(), new NumberType(), BooleanType::class];
        yield 'equality of a literal against its enum' => [
            $comparison, '=', new LiteralType('shop'), new UnionType(new LiteralType('shop'), new LiteralType('office')), BooleanType::class,
        ];
        yield 'the emptiness test: option against the null literal' => [
            $comparison, '==', new OptionType(new NumberType()), new OptionType(new NeverType()), BooleanType::class,
        ];
        yield 'ordering of numbers' => [$comparison, '<', new NumberType(), new NumberType(), BooleanType::class];
        yield 'ordering of number literals' => [$comparison, '>=', new LiteralType(1), new LiteralType(2), BooleanType::class];
        yield 'ordering tolerates unknown' => [$comparison, '>', new UnknownType(), new NumberType(), BooleanType::class];

        $logical = new LogicalOverloader();
        yield 'booleans conjoin' => [$logical, '&&', new BooleanType(), new BooleanType(), BooleanType::class];
        yield 'unknown is admitted at logic' => [$logical, '||', new UnknownType(), new BooleanType(), BooleanType::class];

        $has = new HasOverloader();
        yield 'list has element' => [$has, 'has', new ListType(new StringType()), new StringType(), BooleanType::class];
        yield 'list has enum member' => [
            $has, 'has', new ListType(new StringType()), new UnionType(new LiteralType('a'), new LiteralType('b')), BooleanType::class,
        ];
        yield 'list has subset' => [$has, 'has', new ListType(new StringType()), new ListType(new StringType()), BooleanType::class];
        yield 'has tolerates an absent needle' => [
            $has, 'has', new ListType(new StringType()), new OptionType(new StringType()), BooleanType::class,
        ];
        yield 'has against the empty list is vacuously legal' => [
            $has, 'has', new ListType(new NeverType(), 0, 0), new StringType(), BooleanType::class,
        ];
        yield 'has with a null needle is vacuously legal' => [
            $has, 'has', new ListType(new StringType()), new OptionType(new NeverType()), BooleanType::class,
        ];
        yield 'unknown list side certifies membership' => [$has, 'has', new UnknownType(), new StringType(), BooleanType::class];
        yield 'unknown needle certifies membership' => [$has, 'has', new ListType(new StringType()), new UnknownType(), BooleanType::class];

        $in = new InOverloader();
        yield 'element in list' => [$in, 'in', new LiteralType(5), new ListType(new NumberType()), BooleanType::class];
        yield 'subset in list' => [$in, 'in', new ListType(new NumberType()), new ListType(new NumberType()), BooleanType::class];

        $intersects = new IntersectsOverloader();
        yield 'lists intersect' => [$intersects, 'intersects', new ListType(new StringType()), new ListType(new StringType()), BooleanType::class];
        yield 'scalar intersects list' => [$intersects, 'intersects', new StringType(), new ListType(new StringType()), BooleanType::class];
        yield 'intersects tolerates absence' => [
            $intersects, 'intersects', new OptionType(new ListType(new StringType())), new ListType(new StringType()), BooleanType::class,
        ];
        yield 'intersects with unknown side' => [$intersects, 'intersects', new UnknownType(), new ListType(new StringType()), BooleanType::class];
        yield 'intersects with an empty list is vacuously legal' => [
            $intersects, 'intersects', new ListType(new NeverType(), 0, 0), new ListType(new StringType()), BooleanType::class,
        ];
        yield 'intersects with an empty list on the right is vacuously legal' => [
            $intersects, 'intersects', new ListType(new StringType()), new ListType(new NeverType(), 0, 0), BooleanType::class,
        ];
    }

    #[Test]
    #[DataProvider('refusedCases')]
    public function it_refuses(OperatorOverloader $rule, string $operator, Type $left, Type $right, string $fragment, bool $dead = false): void
    {
        $result = $rule->typeOf($operator, $left, $right);

        $this->assertTrue($result->isErr(), 'expected a refusal');
        $this->assertStringContainsString($fragment, $result->unwrapErr()->describe());
        $this->assertSame($dead, $result->unwrapErr()->dead);
    }

    public static function refusedCases(): \Generator
    {
        $binary = new BinaryOverloader();
        yield 'arithmetic refuses strings' => [$binary, '+', new StringType(), new NumberType(), 'The left operand is not a present number.'];
        yield 'arithmetic refuses options' => [
            $binary, '-', new OptionType(new NumberType()), new NumberType(), 'the value may be absent',
        ];
        yield 'arithmetic refuses a cross-base union' => [
            $binary, '+', new UnionType(new NumberType(), new StringType()), new NumberType(), 'every union member must be assignable',
        ];
        yield 'arithmetic refuses unhandled operators' => [$binary, 'has', new NumberType(), new NumberType(), 'Arithmetic does not handle [has].'];

        $comparison = new ComparisonOverloader();
        yield 'a dead comparison is refused as dead' => [
            $comparison, '==', new NumberType(), new StringType(), 'can never hold', true,
        ];
        yield 'dead negated equality is constant-true, and says so' => [
            $comparison, '!=', new NumberType(), new StringType(), 'always holds', true,
        ];
        yield 'dead strict negated equality says so too' => [
            $comparison, '!==', new NumberType(), new StringType(), 'always holds', true,
        ];
        yield 'a dead comparison carries the overlap cause' => [
            $comparison, '==', new NumberType(), new StringType(), 'Number and String share no values.', true,
        ];
        yield 'a dead enum comparison names the union' => [
            $comparison, '=', new UnionType(new LiteralType('shop'), new LiteralType('office')), new LiteralType('warehouse'), "can never hold", true,
        ];
        yield 'ordering refuses strings' => [$comparison, '<', new StringType(), new StringType(), 'has no defined order'];
        yield 'ordering refuses options' => [
            $comparison, '<=', new OptionType(new NumberType()), new NumberType(), 'has no defined order',
        ];
        yield 'comparison refuses unhandled operators' => [$comparison, '+', new NumberType(), new NumberType(), 'Comparison does not handle [+].'];

        $logical = new LogicalOverloader();
        yield 'logic refuses numbers' => [$logical, '&&', new NumberType(), new BooleanType(), 'The left operand is not a present boolean.'];
        yield 'logic refusals carry the assignability cause' => [
            $logical, '&&', new NumberType(), new BooleanType(), 'Number is not assignable to Boolean',
        ];
        yield 'logic refuses optional booleans' => [
            $logical, '||', new BooleanType(), new OptionType(new BooleanType()), 'The right operand is not a present boolean.',
        ];
        yield 'logic refuses unhandled operators' => [$logical, '==', new BooleanType(), new BooleanType(), 'Logic does not handle [==].'];

        $null = new NullOverloader();
        yield 'the null rule contributes nothing' => [
            $null, '+', new OptionType(new NeverType()), new OptionType(new NeverType()), 'contributes no static admissibility',
        ];

        $has = new HasOverloader();
        yield 'has refuses a non-list left side' => [$has, 'has', new StringType(), new StringType(), 'must be a present list'];
        yield 'has refuses an absent list side' => [
            $has, 'has', new OptionType(new ListType(new StringType())), new StringType(), 'must be a present list',
        ];
        yield 'has refuses a dict left side' => [$has, 'has', new DictType(new StringType()), new StringType(), 'must be a present list'];
        yield 'has refuses a record needle' => [
            $has, 'has', new ListType(new StringType()), new \Superscript\Axiom\Types\RecordType(['a' => new NumberType()]), 'must be a scalar or a list',
        ];
        yield 'dead membership is refused as dead' => [
            $has, 'has', new ListType(new NumberType()), new StringType(), 'can never hold', true,
        ];
        yield 'dead membership carries the element cause' => [
            $has, 'has', new ListType(new NumberType()), new StringType(), 'Number and String share no values.', true,
        ];
        yield 'has refuses unhandled operators' => [$has, 'in', new ListType(new StringType()), new StringType(), 'Membership does not handle [in].'];

        $in = new InOverloader();
        yield 'in refuses a non-list right side' => [$in, 'in', new StringType(), new StringType(), 'must be a present list'];
        yield 'dead in-membership is refused as dead' => [
            $in, 'in', new StringType(), new ListType(new NumberType()), 'can never hold', true,
        ];
        yield 'in refuses unhandled operators' => [$in, 'has', new StringType(), new ListType(new StringType()), 'Membership does not handle [has].'];

        $intersects = new IntersectsOverloader();
        yield 'dead intersection is refused as dead' => [
            $intersects, 'intersects', new ListType(new NumberType()), new ListType(new StringType()), 'can never hold', true,
        ];
        yield 'dead intersection carries the element cause' => [
            $intersects, 'intersects', new ListType(new NumberType()), new ListType(new StringType()), 'Number and String share no values.', true,
        ];
        yield 'intersects refuses dicts, naming the left offender' => [
            $intersects, 'intersects', new DictType(new StringType()), new ListType(new StringType()), 'got Dict<String>',
        ];
        yield 'intersects refuses a record on the right, naming it' => [
            $intersects, 'intersects', new ListType(new StringType()), new \Superscript\Axiom\Types\RecordType(['a' => new NumberType()]), 'got {a: Number}',
        ];
        yield 'intersects refuses unhandled operators' => [
            $intersects, 'has', new ListType(new StringType()), new ListType(new StringType()), 'Intersection does not handle [has].',
        ];
    }

    #[Test]
    public function handles_reflects_the_operator_vocabulary(): void
    {
        $this->assertTrue((new BinaryOverloader())->handles('+'));
        $this->assertFalse((new BinaryOverloader())->handles('has'));
        $this->assertTrue((new ComparisonOverloader())->handles('<'));
        $this->assertTrue((new ComparisonOverloader())->handles('=='));
        $this->assertFalse((new ComparisonOverloader())->handles('+'));
        $this->assertTrue((new LogicalOverloader())->handles('xor'));
        $this->assertFalse((new LogicalOverloader())->handles('=='));
        $this->assertTrue((new NullOverloader())->handles('-'));
        $this->assertFalse((new NullOverloader())->handles('has'));
        $this->assertTrue((new HasOverloader())->handles('has'));
        $this->assertFalse((new HasOverloader())->handles('in'));
        $this->assertTrue((new InOverloader())->handles('in'));
        $this->assertFalse((new InOverloader())->handles('has'));
        $this->assertTrue((new IntersectsOverloader())->handles('intersects'));
        $this->assertFalse((new IntersectsOverloader())->handles('in'));
    }
}
