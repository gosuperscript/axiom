<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * The one question every rule answers: resolve(operator, operand types) —
 * certification carries the return type, refusal carries the diagnosis.
 * Rows (arithmetic, ordering, logic) are exercised through the composed
 * core dialect.
 */
#[CoversClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOverloaderManager::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(DictType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DictShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
final class ResolveTest extends TestCase
{
    private static function core(): OperatorOverloader
    {
        return Dialect::core()->operators();
    }


    #[Test]
    #[DataProvider('certifiedCases')]
    public function it_certifies(OperatorOverloader $rule, string $operator, Type $left, Type $right, string $expected): void
    {
        $result = $rule->resolve($operator, $left, $right);

        $this->assertTrue($result->isOk(), $result->isErr() ? $result->unwrapErr()->describe() : '');
        $this->assertInstanceOf($expected, $result->unwrap()->returns);
    }

    public static function certifiedCases(): \Generator
    {
        $core = self::core();
        yield 'numbers add to Number' => [$core, '+', new NumberType(), new NumberType(), NumberType::class];
        yield 'number literals divide to Number' => [$core, '/', new LiteralType(5), new LiteralType(2), NumberType::class];
        yield 'a numeric enum multiplies' => [
            $core, '*', new UnionType(new LiteralType(1), new LiteralType(2)), new NumberType(), NumberType::class,
        ];
        yield 'ordering of numbers' => [$core, '<', new NumberType(), new NumberType(), BooleanType::class];
        yield 'ordering of number literals' => [$core, '>=', new LiteralType(1), new LiteralType(2), BooleanType::class];
        yield 'booleans conjoin' => [$core, '&&', new BooleanType(), new BooleanType(), BooleanType::class];




    }

    #[Test]
    #[DataProvider('refusedCases')]
    public function it_refuses(OperatorOverloader $rule, string $operator, Type $left, Type $right, string $fragment, bool $dead = false): void
    {
        $result = $rule->resolve($operator, $left, $right);

        $this->assertTrue($result->isErr(), 'expected a refusal');
        $this->assertStringContainsString($fragment, $result->unwrapErr()->describe());
        $this->assertSame($dead, $result->unwrapErr()->dead);
    }

    public static function refusedCases(): \Generator
    {
        $core = self::core();
        yield 'arithmetic refuses strings' => [$core, '+', new StringType(), new NumberType(), '[+] expects Number and Number; got String and Number.'];
        yield 'arithmetic refuses options' => [
            $core, '-', new OptionType(new NumberType()), new NumberType(), 'the value may be absent',
        ];
        yield 'arithmetic refuses a cross-base union' => [
            $core, '+', new UnionType(new NumberType(), new StringType()), new NumberType(), 'every union member must be assignable',
        ];
        // Unknown is inert: no rule resolves it, and the refusal names the fix.
        yield 'arithmetic refuses an inert Unknown' => [
            $core, '*', new UnknownType(), new NumberType(), 'An Unknown operand is inert',
        ];
        yield 'ordering refuses an inert Unknown' => [
            $core, '>', new UnknownType(), new NumberType(), 'An Unknown operand is inert',
        ];
        yield 'logic refuses an inert Unknown' => [
            $core, '||', new UnknownType(), new BooleanType(), 'An Unknown operand is inert',
        ];
        yield 'ordering refuses strings' => [$core, '<', new StringType(), new StringType(), '[<] expects Number and Number; got String and String.'];
        yield 'ordering refuses options' => [
            $core, '<=', new OptionType(new NumberType()), new NumberType(), 'the value may be absent',
        ];
        yield 'logic refuses numbers' => [$core, '&&', new NumberType(), new BooleanType(), '[&&] expects Boolean and Boolean; got Number and Boolean.'];
        yield 'logic refusals carry the assignability cause' => [
            $core, '&&', new NumberType(), new BooleanType(), 'Number is not assignable to Boolean',
        ];
        yield 'logic refuses optional booleans' => [
            $core, '||', new BooleanType(), new OptionType(new BooleanType()), '[||] expects Boolean and Boolean',
        ];
        yield 'an unsupported operator is refused by the whole dialect' => [
            $core, 'coalesce', new NumberType(), new NumberType(), 'Operator [coalesce] is not supported.',
        ];
        // NullOverloader is deleted: absence-tolerant arithmetic certifies
        // nothing in core, so the null literal pair is an ordinary refusal.
        yield 'null arithmetic resolves nothing in core' => [
            $core, '+', new OptionType(new NeverType()), new OptionType(new NeverType()), '[+] expects Number and Number',
        ];

        // Totality: Ok certifies EVERY value of the operand types, and value
        // equality makes no claim about objects — so opaque-typed operands
        // are unsupported (not dead: no evaluation exists for them here).


        // Universal over union members: one supported branch certifies
        // nothing, and opaque needles are objects value equality never claims.

    }

    #[Test]
    public function a_foreign_operator_refusal_is_marked_unhandled(): void
    {
        $addition = Operator::infix('+')
            ->signature(new NumberType(), new NumberType())
            ->returns(new NumberType())
            ->evaluate(fn(int|float $a, int|float $b) => $a + $b);

        $this->assertTrue($addition->resolve('-', new NumberType(), new NumberType())->unwrapErr()->unhandled);
    }

    #[Test]
    public function an_engaged_refusal_is_not_marked_unhandled(): void
    {
        $addition = Operator::infix('+')
            ->signature(new NumberType(), new NumberType())
            ->returns(new NumberType())
            ->evaluate(fn(int|float $a, int|float $b) => $a + $b);

        $this->assertFalse($addition->resolve('+', new NumberType(), new StringType())->unwrapErr()->unhandled);
    }
}
