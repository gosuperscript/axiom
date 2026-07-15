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
use Superscript\Axiom\Operators\ResolvedOperation;
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
#[CoversClass(ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
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


        // Equality is value equality, never PHP juggling: numeric within
        // Number (1 == 1.0 — one base), and === is an alias of ==.




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
