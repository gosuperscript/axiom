<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Operators\Equality;
use Superscript\Axiom\Operators\Has;
use Superscript\Axiom\Operators\In;
use Superscript\Axiom\Operators\Intersects;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnionType;
use Superscript\Monads\Result\Result;

/**
 * The runtime face of the core dialect, reached the only way it can be
 * reached now: resolve with the operand types, then run the returned
 * evaluation on values of them. Pairs the checker refuses (dead
 * comparisons, cross-base equality between disjoint literals) have no
 * evaluation to test — that is the point; see ResolveTest for the
 * refusals.
 */
#[CoversClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\OptionLayers::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[CoversClass(Equality::class)]
#[CoversClass(Has::class)]
#[CoversClass(In::class)]
#[CoversClass(Intersects::class)]
#[CoversClass(ValueEquality::class)]
#[CoversClass(ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[CoversClass(\Superscript\Axiom\Operators\SetOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
final class CoreDialectEvaluationTest extends TestCase
{
    /**
     * @return Result<mixed, \Throwable>
     */
    private static function evaluate(mixed $left, string $operator, mixed $right): Result
    {
        $resolution = Dialect::core()->operators()->resolve($operator, self::typeOfValue($left), self::typeOfValue($right));

        return $resolution->unwrap()->evaluate($left, $right);
    }

    /**
     * Base types, deliberately not literal types: this suite tests the
     * evaluations, and literal-precise operand types would turn every
     * negative row (1 == 2, 'c' in ['a', 'b']) into a dead compile error
     * with no evaluation left to run. Programs get literal precision from
     * inference; closures must be total over the bases.
     */
    private static function typeOfValue(mixed $value): Type
    {
        if ($value === null) {
            return new OptionType(new NeverType());
        }

        if (is_array($value)) {
            $types = [];

            foreach (array_values($value) as $element) {
                $type = self::typeOfValue($element);
                $types[\Superscript\Axiom\Types\TypeDescriber::describe($type)] = $type;
            }

            $types = array_values($types);

            // Plain [0, ∞) bounds: exact lengths would make same-operator
            // rows dead across different-length operands (['a'] == ['a','a']
            // must evaluate false, not refuse to compile).
            return new ListType(match (count($types)) {
                0 => new NeverType(),
                1 => $types[0],
                default => new UnionType(...$types),
            });
        }

        if (is_bool($value)) {
            return new \Superscript\Axiom\Types\BooleanType();
        }

        if (is_int($value) || is_float($value)) {
            return new NumberType();
        }

        if (is_string($value)) {
            return new StringType();
        }

        throw new \InvalidArgumentException('No specimen type for ' . get_debug_type($value));
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_evaluates(mixed $left, string $operator, mixed $right, mixed $expected): void
    {
        $this->assertSame($expected, self::evaluate($left, $operator, $right)->unwrap());
    }

    public static function cases(): Generator
    {
        yield [1, '+', 2, 3];
        yield [3, '-', 2, 1];
        yield [2, '*', 3, 6];
        yield [6, '/', 3, 2];
        yield [1.5, '+', 1, 2.5];

        yield [1, '>', 2, false];
        yield [2, '>', 2, false];
        yield [3, '>', 2, true];

        yield [1, '>=', 2, false];
        yield [2, '>=', 2, true];
        yield [3, '>=', 2, true];

        yield [1, '<', 2, true];
        yield [2, '<', 2, false];
        yield [3, '<', 2, false];

        yield [1, '<=', 2, true];
        yield [2, '<=', 2, true];
        yield [3, '<=', 2, false];

        yield [1, '<', 2.5, true];
        yield [2.5, '>', 1, true];

        yield [1, '=', 1, true];
        yield [1, '=', 2, false];
        yield [1, '!=', 2, true];
        yield [1, '!=', 1, false];

        // Equality is value equality, never PHP juggling: numeric within
        // Number (1 == 1.0 — one base), and === is an alias of ==.
        yield [1, '==', 1.0, true];
        yield [1, '===', 1.0, true];
        yield [1, '===', 1, true];
        yield [1, '!==', 2, true];

        yield [null, '==', null, true];
        yield ['a', '==', 'a', true];
        yield [['a', 'b'], '==', ['a', 'b'], true];
        yield [['a', 'b'], '==', ['b', 'a'], false];
        yield [['a', ['b']], '==', ['a', ['c']], false];
        yield [['a'], '==', ['a', 'a'], false];

        yield [['a', 'b'], 'has', 'a', true];
        yield [['a', 'b'], 'has', 'c', false];
        yield [['a', 'b'], 'has', ['a', 'b'], true];
        yield [['a', 'b'], 'has', ['a', 'c'], false];
        yield [['a', 'b', 'c'], 'has', ['a', 'c'], true];
        yield [['a', 'b'], 'has', null, false];
        yield [[null, 'a'], 'has', 'a', true];
        yield [[''], 'has', null, false];

        yield ['a', 'in', ['a', 'b'], true];
        yield ['c', 'in', ['a', 'b'], false];
        yield [['a', 'b'], 'in', ['a', 'b'], true];
        yield [['a', 'b'], 'in', ['a', 'c'], false];
        yield [['a', 'c'], 'in', ['a', 'b', 'c'], true];
        yield [['a', 'b', 'c'], 'in', ['a', 'c'], false];
        yield [null, 'in', ['a', 'b'], false];
        yield [[null, 'a'], 'in', ['a', 'b'], true];

        yield [true, '&&', true, true];
        yield [true, '&&', false, false];
        yield [false, '&&', true, false];
        yield [false, '&&', false, false];
        yield [true, '||', true, true];
        yield [true, '||', false, true];
        yield [false, '||', true, true];
        yield [false, '||', false, false];
        yield [true, 'xor', true, false];
        yield [true, 'xor', false, true];

        yield ['a', 'intersects', ['a', 'b'], true];
        yield ['c', 'intersects', ['a', 'b'], false];
        yield [['a', 'b'], 'intersects', ['a'], true];
        yield [['a', 'b'], 'intersects', ['c'], false];
        yield [['a', 'b'], 'intersects', ['a', 'c'], true];
        yield [['a', 'b'], 'intersects', ['c', 'd'], false];
        yield ['a', 'intersects', 'a', true];
        yield ['a', 'intersects', 'b', false];
        yield [null, 'intersects', ['a', 'b'], false];
        yield [['a', 'b'], 'intersects', null, false];
        yield [null, 'intersects', null, false];
        yield [[null, 'a'], 'intersects', ['a', 'b'], true];
    }

    #[Test]
    public function division_by_zero_is_a_value_dependent_runtime_error(): void
    {
        $result = self::evaluate(6, '/', 0);

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(\DivisionByZeroError::class, $result->unwrapErr());
    }

    /**
     * Cross-base equality between disjoint literal types is a dead compile
     * error, so the runtime face of "5 is not '5'" lives where the types
     * genuinely overlap: a union-typed operand whose value arrives in the
     * other base.
     */
    #[Test]
    public function equality_across_bases_is_false_where_the_types_overlap(): void
    {
        $operation = (new Equality('==', negated: false))
            ->resolve(new UnionType(new NumberType(), new StringType()), new NumberType());

        $this->assertInstanceOf(ResolvedOperation::class, $operation);

        $this->assertFalse($operation->evaluate('5', 5)->unwrap());
        $this->assertTrue($operation->evaluate(5, 5)->unwrap());
        $this->assertTrue($operation->evaluate(5.0, 5)->unwrap());

        $negated = (new Equality('!=', negated: true))
            ->resolve(new UnionType(new NumberType(), new StringType()), new NumberType());

        $this->assertInstanceOf(ResolvedOperation::class, $negated);

        $this->assertTrue($negated->evaluate('5', 5)->unwrap());
        $this->assertFalse($negated->evaluate(5, 5)->unwrap());

        // true is not 1 — value equality is false across bases.
        $boolean = (new Equality('==', negated: false))
            ->resolve(new UnionType(new \Superscript\Axiom\Types\BooleanType(), new NumberType()), new NumberType());

        $this->assertInstanceOf(ResolvedOperation::class, $boolean);

        $this->assertFalse($boolean->evaluate(true, 1)->unwrap());
    }

    #[Test]
    #[DataProvider('unaryCases')]
    public function it_evaluates_unary(string $operator, mixed $operand, mixed $expected): void
    {
        $resolution = Dialect::core()->unaryOperators()->resolve($operator, self::typeOfValue($operand));

        $this->assertSame($expected, $resolution->unwrap()->evaluate($operand)->unwrap());
    }

    public static function unaryCases(): Generator
    {
        yield ['!', true, false];
        yield ['!', false, true];
        yield ['not', true, false];
        yield ['not', false, true];
        yield ['-', 5, -5];
        yield ['-', -2.5, 2.5];
    }
}
